<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Database\IsolationHealth;
use Kalaka\Foundation\SiteWarning\SiteWarnings;
use Kalaka\Tests\Integration\Foundation\SiteWarning\SiteWarningTestCase;

/**
 * The database's site warning: the Site Health test `kalaka_database_isolation` and the banner,
 * critical while `BinlogCheck` refuses, passed when the variables cannot be read. The answer is faked
 * (`FakeBinlog`).
 */
final class IsolationHealthTest extends SiteWarningTestCase
{
    private const CRITICAL_LABEL = 'Az adatbázis beállítása miatt a Kaláka nem tud adatot menteni';

    private const CRITICAL_DESCRIPTION = '<p>A webtárhely adatbázisszerverén be van kapcsolva a bináris naplózás, és '
        . 'a binlog_format értéke STATEMENT. Ilyenkor a Kaláka minden mentést visszautasít. A tárhely '
        . 'üzemeltetőjének a binlog_format értékét MIXED-re vagy ROW-ra kell állítania.</p>';

    private const GOOD_LABEL = 'Az adatbázis beállítása megfelel a Kalákának';

    private const GOOD_DESCRIPTION = '<p>A webtárhely adatbázisszervere engedi, hogy a Kaláka minden mentést '
        . 'READ COMMITTED elkülönítési szinten végezzen.</p>';

    private function register(): void
    {
        $warnings = new SiteWarnings();
        $warnings->add(new IsolationHealth(new BinlogCheck()));
        $warnings->register();
    }

    /** @return array<string, mixed> */
    private function test(): array
    {
        $tests = $this->siteHealthTests();
        self::assertIsArray($tests[IsolationHealth::SITE_HEALTH_TEST]);
        self::assertSame(self::GOOD_LABEL, $tests[IsolationHealth::SITE_HEALTH_TEST]['label']);

        return $this->siteHealth(IsolationHealth::SITE_HEALTH_TEST);
    }

    public function testWhileTheCheckRefusesTheSiteHealthTestIsCriticalAndTheBannerShows(): void
    {
        FakeBinlog::statementFormat();
        $this->register();

        self::assertSame(
            self::result('kalaka_database_isolation', self::CRITICAL_LABEL, 'critical', self::CRITICAL_DESCRIPTION),
            $this->test()
        );
        self::assertSame(
            self::banner('notice-error', self::CRITICAL_LABEL, self::CRITICAL_DESCRIPTION),
            $this->notices()
        );
    }

    /** @return iterable<string, array{\Closure(): ?FakeBinlog}> */
    public static function writesWork(): iterable
    {
        yield 'the test server' => [static fn (): ?FakeBinlog => null];
        yield 'binary logging with MIXED' => [static fn (): FakeBinlog => FakeBinlog::answering('1', 'MIXED')];
        yield 'the variables cannot be read' => [static fn (): FakeBinlog => FakeBinlog::failing()];
        yield 'the read returns nothing' => [static fn (): FakeBinlog => FakeBinlog::empty()];
    }

    /**
     * @dataProvider writesWork
     * @param \Closure(): ?FakeBinlog $answer
     */
    public function testOtherwiseTheSiteHealthTestPassesAndNoBannerShows(\Closure $answer): void
    {
        $answer();
        $this->register();

        self::assertSame(
            self::result('kalaka_database_isolation', self::GOOD_LABEL, 'good', self::GOOD_DESCRIPTION),
            $this->test()
        );
        self::assertSame('', $this->notices());
    }

    public function testAUserWithoutTheSiteHealthCapabilitySeesNoBannerAndRunsNoCheck(): void
    {
        $fake = FakeBinlog::statementFormat();
        $this->register();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        self::assertSame('', $this->notices());
        self::assertSame(0, $fake->reads);
    }

    public function testTheSiteHealthTestRunsWithoutAUserForTheWeeklyCronCheck(): void
    {
        FakeBinlog::statementFormat();
        $this->register();
        wp_set_current_user(0);

        self::assertSame('critical', $this->test()['status']);
    }

    public function testTheCheckWritesNothing(): void
    {
        $fake = FakeBinlog::statementFormat();
        $this->register();
        $statements = [];
        $capture = static function (string $query) use (&$statements): string {
            $statements[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        $this->notices();
        $this->test();
        remove_filter('query', $capture);

        self::assertSame(1, $fake->reads);
        self::assertSame([], preg_grep('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statements));
    }
}
