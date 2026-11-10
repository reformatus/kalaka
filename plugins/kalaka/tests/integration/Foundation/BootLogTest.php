<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Boot;
use Kalaka\Foundation\SchemaUpgrader;

/** A load failure is logged when it starts or changes, never on every request (#146 AC5, AC6). */
final class BootLogTest extends RunnerTestCase
{
    /** @var list<\Kalaka\Foundation\Api\LoadFailure> */
    private array $state;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        $this->state = LoadState::current()->failures;
        // Only the modules a test boots: nothing registered on the hook joins in.
        remove_all_actions('kalaka_register_modules');
        delete_option(Boot::FAILURE_OPTION);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        LoadState::record($this->state);
        parent::tear_down();
    }

    private function boot(AbstractModule ...$modules): string
    {
        return $this->capturedErrorLog(static function () use ($modules): void {
            (new Boot(array_values($modules)))->run();
        });
    }

    private static function failing(string $id, string $message): AbstractModule
    {
        return new class ($id, $message) extends AbstractModule {
            public function __construct(private readonly string $moduleId, private readonly string $message)
            {
            }

            public function id(): string
            {
                return $this->moduleId;
            }

            public function requiresApi(): string
            {
                return '>=0.0';
            }

            public function boot(): void
            {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test message, never output
                throw new \RuntimeException($this->message . "\nsecond line");
            }
        };
    }

    private static function upgrading(): AbstractModule
    {
        return new class extends AbstractModule {
            public function id(): string
            {
                return 'boot_log_upgrading';
            }

            public function requiresApi(): string
            {
                return '>=0.0';
            }

            public function schema(): Schema
            {
                return new Schema(1, []);
            }

            public function boot(): void
            {
            }
        };
    }

    /** @return list<string> the log's lines, without PHP's time stamps */
    private static function lines(string $log): array
    {
        return array_values(array_map(
            static fn (string $line): string => (string) preg_replace('/^\[[^\]]*\] /', '', $line),
            array_filter(explode("\n", trim($log)))
        ));
    }

    public function testTheFirstFailureIsLoggedWithItsWholeChainOnOneLine(): void
    {
        $log = $this->boot(self::failing('boot_log_a', 'broken'));

        self::assertSame(
            [
                'Kaláka: loading module boot_log_a failed, correlation ' . Correlation::id()
                . ': RuntimeException (0): broken second line',
            ],
            self::lines($log)
        );
        self::assertFalse(LoadState::current()->isOpen());
        self::assertIsString(get_option(Boot::FAILURE_OPTION));
    }

    public function testTheSameFailureAgainWritesNothing(): void
    {
        $this->boot(self::failing('boot_log_a', 'broken'));

        self::assertSame('', $this->boot(self::failing('boot_log_a', 'broken')));
    }

    public function testAChangedFailureIsLoggedAgain(): void
    {
        $this->boot(self::failing('boot_log_a', 'broken'));

        $log = $this->boot(self::failing('boot_log_a', 'broken'), self::failing('boot_log_b', 'also broken'));

        self::assertCount(2, self::lines($log));
    }

    public function testACleanLoadForgetsTheFailure(): void
    {
        $this->boot(self::failing('boot_log_a', 'broken'));

        self::assertSame('', $this->boot());
        self::assertFalse(get_option(Boot::FAILURE_OPTION));
        self::assertTrue(LoadState::current()->isOpen());

        self::assertCount(1, self::lines($this->boot(self::failing('boot_log_a', 'broken'))));
    }

    public function testAnUpgradeAnotherRequestRunsClosesTheRequestButIsNotLoggedAndKeepsTheFingerprint(): void
    {
        $this->boot(self::failing('boot_log_a', 'broken'));
        $fingerprint = get_option(Boot::FAILURE_OPTION);
        $module = self::upgrading();
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        self::assertTrue(\WP_Upgrader::create_lock((new SchemaUpgrader())->lockName($module), 600));

        try {
            $log = $this->boot($module);
        } finally {
            \WP_Upgrader::release_lock((new SchemaUpgrader())->lockName($module));
            delete_option((new SchemaUpgrader())->versionOption($module));
        }

        self::assertSame('', $log);
        self::assertFalse(LoadState::current()->isOpen());
        self::assertSame($fingerprint, get_option(Boot::FAILURE_OPTION));
    }
}
