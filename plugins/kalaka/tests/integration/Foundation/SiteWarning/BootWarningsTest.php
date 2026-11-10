<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\SiteWarning;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Boot;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Tests\Integration\Foundation\FakeBinlog;

/** The boot registers the warnings itself, before any module, and adds the contributed ones. */
final class BootWarningsTest extends SiteWarningTestCase
{
    private const LOAD_TITLE = 'A Kaláka nem töltődött be, ezért minden esemény zárva van';

    private const LOAD_DESCRIPTION = 'A jelentkezés, a résztvevői oldalak és a levélküldés nem működik. A tárolt '
        . 'adatok érintetlenek; a hiba elhárítása után a Kaláka újra működik.';

    private const BINLOG_TITLE = 'Az adatbázis beállítása miatt a Kaláka nem tud adatot menteni';

    private const ERROR_LOG_TITLE = 'A PHP-hibanapló a webgyökér alatt van, így letölthető lehet';

    private const STALL_TITLE = 'Az időzített események feldolgozása nem működik';

    private string $previousLog;

    /** The contributions of the real boot, which a test boot replaces. */
    private Contributions $contributions;

    /** @var list<\Kalaka\Foundation\Api\LoadFailure> */
    private array $state;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // Only the modules a test boots: nothing registered on the hook joins in.
        remove_all_actions('kalaka_register_modules');
        delete_option(Boot::FAILURE_OPTION);
        delete_option(PeriodicRun::LAST_RUN_OPTION);
        $this->previousLog = (string) ini_get('error_log');
        $this->contributions = Contributions::current();
        $this->state = LoadState::current()->failures;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        ini_set('error_log', $this->previousLog);
        delete_option(Boot::FAILURE_OPTION);
        Contributions::record($this->contributions);
        LoadState::record($this->state);

        parent::tear_down();
    }

    private function boot(AbstractModule ...$modules): void
    {
        $this->capturedErrorLog(static function () use ($modules): void {
            (new Boot(array_values($modules)))->run();
        });
    }

    private static function module(string $id, ?\Closure $contribute = null, ?string $failure = null): AbstractModule
    {
        return new class ($id, $contribute, $failure) extends AbstractModule {
            public function __construct(
                private readonly string $moduleId,
                private readonly ?\Closure $contributions,
                private readonly ?string $failure,
            ) {
            }

            public function id(): string
            {
                return $this->moduleId;
            }

            public function requiresApi(): string
            {
                return '>=0.0';
            }

            public function contribute(Contributions $contributions): void
            {
                if ($this->contributions !== null) {
                    ($this->contributions)($contributions);
                }
            }

            public function boot(): void
            {
                if ($this->failure !== null) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a test message, never output
                    throw new \RuntimeException($this->failure);
                }
            }
        };
    }

    /** @return list<string> the banners' titles, in the order shown */
    private function titles(): array
    {
        preg_match_all('#<p><strong>(.*?)</strong></p>#', $this->notices(), $matches);

        return $matches[1];
    }

    public function testAFailedLoadShowsItsBannerWithOneLinePerFailureWithoutFoundationsModule(): void
    {
        $this->boot(
            self::module('boot_warnings_a', failure: 'broken <here>'),
            self::module('boot_warnings_b', failure: 'also')
        );

        self::assertSame(
            self::banner(
                'notice-error',
                self::LOAD_TITLE,
                '<p>' . self::LOAD_DESCRIPTION . '</p><details><summary>Részletek</summary>'
                . '<p>boot_warnings_a: broken &lt;here&gt;<br>boot_warnings_b: also</p></details>'
            ),
            $this->notices()
        );
        self::assertArrayNotHasKey('kalaka_load_failure', $this->siteHealthTests());
    }

    public function testTheBootsWarningsShowInTheirOrderAndTheStallHasNoBannerOrTestWhileTheSiteIsClosed(): void
    {
        FakeBinlog::statementFormat();
        ini_set('error_log', ABSPATH . 'wp-content/debug.log');
        $this->boot(self::module('boot_warnings_a', failure: 'broken'));

        self::assertSame([self::LOAD_TITLE, self::BINLOG_TITLE, self::ERROR_LOG_TITLE], $this->titles());
        self::assertSame(
            ['kalaka_database_isolation', 'kalaka_error_log'],
            array_keys($this->siteHealthTests())
        );
    }

    public function testOnAnOpenSiteTheStallShowsAfterTheErrorLog(): void
    {
        ini_set('error_log', ABSPATH . 'wp-content/debug.log');
        $this->boot();

        self::assertSame([self::ERROR_LOG_TITLE, self::STALL_TITLE], $this->titles());
    }

    public function testContributedWarningsShowAfterTheBootsOwnErrorsFirst(): void
    {
        $this->boot(self::module('boot_warnings_a', static function (Contributions $contributions): void {
            $contributions->siteWarning(new FakeWarning('info', new Shown(Severity::Info, 'Info', 'Leírás.')));
            $contributions->siteWarning(new FakeWarning('error', new Shown(Severity::Error, 'Hiba', 'Leírás.')));
        }));

        self::assertSame([self::STALL_TITLE, 'Hiba', 'Info'], $this->titles());
    }
}
