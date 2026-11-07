<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Database\IsolationHealth;
use WP_UnitTestCase;

/**
 * The database's health check: the Site Health test `kalaka_database_isolation` and the admin notice,
 * critical while `BinlogCheck` refuses (AC6), passed when the variables cannot be read (AC8). The answer
 * is faked (`FakeBinlog`).
 */
final class IsolationHealthTest extends WP_UnitTestCase
{
    private const CRITICAL_LABEL = 'Az adatbázis beállítása miatt a Kaláka nem tud adatot menteni!';

    private const CRITICAL_DESCRIPTION = '<p>A webtárhely adatbázisszerverén be van kapcsolva a bináris naplózás, és '
        . 'a binlog_format értéke STATEMENT. Ilyenkor a Kaláka minden mentést visszautasít. A tárhely '
        . 'üzemeltetőjének a binlog_format értékét MIXED-re vagy ROW-ra kell állítania.</p>';

    private const GOOD_LABEL = 'Az adatbázis beállítása megfelel a Kalákának';

    private const GOOD_DESCRIPTION = '<p>A webtárhely adatbázisszervere engedi, hogy a Kaláka minden mentést '
        . 'READ COMMITTED elkülönítési szinten végezzen.</p>';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        remove_all_actions('admin_notices');
        remove_all_filters('site_status_tests');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function register(): void
    {
        (new IsolationHealth(new BinlogCheck()))->register();
    }

    /** What `admin_notices` prints. */
    private function notices(): string
    {
        ob_start();
        do_action('admin_notices');

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> what the Site Health test returns */
    private function siteHealth(): array
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        self::assertIsArray($tests);
        $test = $tests['direct'][IsolationHealth::SITE_HEALTH_TEST] ?? null;
        self::assertIsArray($test);
        self::assertSame(self::GOOD_LABEL, $test['label']);
        self::assertIsCallable($test['test']);

        return ($test['test'])();
    }

    /** @return array<string, mixed> */
    private static function result(string $label, string $status, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => 'Kaláka', 'color' => 'blue'],
            'description' => $description,
            'actions' => '',
            'test' => 'kalaka_database_isolation',
        ];
    }

    public function testWhileTheCheckRefusesTheSiteHealthTestIsCriticalAndTheNoticeShows(): void
    {
        FakeBinlog::statementFormat();
        $this->register();

        self::assertSame(
            self::result(self::CRITICAL_LABEL, 'critical', self::CRITICAL_DESCRIPTION),
            $this->siteHealth()
        );
        self::assertSame(
            '<div class="notice notice-error"><p><strong>' . self::CRITICAL_LABEL . '</strong></p>'
            . self::CRITICAL_DESCRIPTION . '</div>',
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
    public function testOtherwiseTheSiteHealthTestPassesAndNoNoticeShows(\Closure $answer): void
    {
        $answer();
        $this->register();

        self::assertSame(self::result(self::GOOD_LABEL, 'good', self::GOOD_DESCRIPTION), $this->siteHealth());
        self::assertSame('', $this->notices());
    }

    public function testAUserWithoutTheSiteHealthCapabilitySeesNoNoticeAndRunsNoCheck(): void
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

        self::assertSame('critical', $this->siteHealth()['status']);
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
        $this->siteHealth();
        remove_filter('query', $capture);

        self::assertSame(1, $fake->reads);
        self::assertSame([], preg_grep('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statements));
    }

    public function testBootRegistersTheNoticeAndTheSiteHealthTest(): void
    {
        FakeBinlog::statementFormat();
        remove_all_actions('kalaka_periodic_run');
        remove_all_actions('kalaka_periodic_run_now');

        (new \Kalaka\Foundation\Module())->boot();

        self::assertStringContainsString(self::CRITICAL_LABEL, $this->notices());
        self::assertSame(self::CRITICAL_LABEL, $this->siteHealth()['label']);
    }
}
