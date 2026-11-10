<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Boot;

/**
 * A module's late check closes the site with `LoadState::close()`; the boot logs the late failures with
 * its own, only when they start or change.
 */
final class LateLoadFailureTest extends RunnerTestCase
{
    /** @var list<LoadFailure> */
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
        delete_option(Boot::FAILURE_OPTION);
        parent::tear_down();
    }

    /** One request: `wp_loaded` has fired, so the late checks run in `boot()` and the boot logs at once. */
    private function request(AbstractModule ...$modules): string
    {
        return $this->capturedErrorLog(static function () use ($modules): void {
            (new Boot(array_values($modules)))->run();
        });
    }

    /** A module whose late check finds a failure, as a module's check does when `wp_loaded` has fired. */
    private static function lateFailing(string $id, string $reason): AbstractModule
    {
        return new class ($id, $reason) extends AbstractModule {
            public function __construct(private readonly string $moduleId, private readonly string $reason)
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
                LoadState::close(new LoadFailure($this->moduleId, $this->reason));
            }
        };
    }

    private static function bootFailing(string $id): AbstractModule
    {
        return new class ($id) extends AbstractModule {
            public function __construct(private readonly string $moduleId)
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
                throw new \RuntimeException('broken');
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

    public function testCloseClosesAnOpenSiteAndNeverReopensIt(): void
    {
        LoadState::record([]);
        self::assertTrue(LoadState::current()->isOpen());

        LoadState::close(new LoadFailure('late_a', 'missing'));

        self::assertFalse(LoadState::current()->isOpen());
        self::assertEquals([new LoadFailure('late_a', 'missing')], LoadState::current()->failures);
        self::assertEquals([new LoadFailure('late_a', 'missing')], LoadState::current()->late);
    }

    public function testCloseAddsToTheBootsOutcome(): void
    {
        $boot = new LoadFailure('events', 'one');
        LoadState::record([$boot]);

        LoadState::close(new LoadFailure('late_a', 'two'));

        self::assertEquals([$boot, new LoadFailure('late_a', 'two')], LoadState::current()->failures);
        self::assertEquals([new LoadFailure('late_a', 'two')], LoadState::current()->late);
    }

    public function testALateFailureThatLastsIsLoggedOnceAcrossRequests(): void
    {
        $first = $this->request(self::lateFailing('late_a', 'missing'));

        self::assertSame(
            ['Kaláka: module late_a not loaded: missing, correlation ' . Correlation::id()],
            self::lines($first)
        );
        self::assertFalse(LoadState::current()->isOpen());
        self::assertIsString(get_option(Boot::FAILURE_OPTION));

        self::assertSame('', $this->request(self::lateFailing('late_a', 'missing')));
        self::assertFalse(LoadState::current()->isOpen());
    }

    public function testAChangedLateFailureIsLoggedAgain(): void
    {
        $this->request(self::lateFailing('late_a', 'missing'));

        $log = $this->request(self::lateFailing('late_a', 'missing elsewhere'));

        self::assertSame(
            ['Kaláka: module late_a not loaded: missing elsewhere, correlation ' . Correlation::id()],
            self::lines($log)
        );
    }

    public function testBootAndLateFailuresShareOneFingerprint(): void
    {
        $this->request(self::bootFailing('late_boot'));
        $bootOnly = get_option(Boot::FAILURE_OPTION);

        $log = $this->request(self::bootFailing('late_boot'), self::lateFailing('late_a', 'missing'));

        self::assertCount(2, self::lines($log));
        self::assertNotSame($bootOnly, get_option(Boot::FAILURE_OPTION));
        self::assertSame(
            '',
            $this->request(self::bootFailing('late_boot'), self::lateFailing('late_a', 'missing'))
        );

        $log = $this->request(self::bootFailing('late_boot'));

        self::assertSame($bootOnly, get_option(Boot::FAILURE_OPTION));
        self::assertCount(1, self::lines($log));
    }

    public function testACleanLoadForgetsALateFailure(): void
    {
        $this->request(self::lateFailing('late_a', 'missing'));

        self::assertSame('', $this->request());
        self::assertFalse(get_option(Boot::FAILURE_OPTION));
        self::assertTrue(LoadState::current()->isOpen());

        self::assertCount(1, self::lines($this->request(self::lateFailing('late_a', 'missing'))));
    }

    public function testBeforeWpLoadedTheBootLogsAfterTheLateChecks(): void
    {
        global $wp_actions;
        $fired = $wp_actions['wp_loaded'] ?? null;
        $hooks = $GLOBALS['wp_filter']['wp_loaded'] ?? null;
        unset($wp_actions['wp_loaded']);
        remove_all_actions('wp_loaded');

        update_option(Boot::FAILURE_OPTION, 'stale', true);

        try {
            $beforeWpLoaded = $this->request();
            // Neither logged nor forgotten yet: the boot waits for `wp_loaded`.
            self::assertSame('', $beforeWpLoaded);
            self::assertSame('stale', get_option(Boot::FAILURE_OPTION));
            self::assertTrue(LoadState::current()->isOpen());

            $log = $this->capturedErrorLog(static function (): void {
                add_action('wp_loaded', static function (): void {
                    LoadState::close(new LoadFailure('late_a', 'missing'));
                });
                do_action('wp_loaded');
            });
        } finally {
            if ($fired !== null) {
                $wp_actions['wp_loaded'] = $fired;
            }
            if ($hooks !== null) {
                $GLOBALS['wp_filter']['wp_loaded'] = $hooks;
            } else {
                remove_all_actions('wp_loaded');
            }
        }

        self::assertSame(
            ['Kaláka: module late_a not loaded: missing, correlation ' . Correlation::id()],
            self::lines($log)
        );
        self::assertFalse(LoadState::current()->isOpen());
        self::assertNotSame('stale', get_option(Boot::FAILURE_OPTION));
    }
}
