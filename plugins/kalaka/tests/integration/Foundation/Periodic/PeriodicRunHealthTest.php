<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\PeriodicRunHealth;
use Kalaka\Foundation\Periodic\PeriodicSchedule;

require_once __DIR__ . '/PeriodicTestCase.php';

/** The warning of a stale periodic run (AC2 to AC7 and AC11 of the cron health spec; 20 minutes per the owner's ruling on D1). */
final class PeriodicRunHealthTest extends PeriodicTestCase
{
    private const LATE_LABEL = 'Az időzített események feldolgozása több mint 20 perce megakadt!';

    private const NEVER_LABEL = 'Az időzített események feldolgozása nem működik!';

    private const GOOD_LABEL = 'Az időzített események feldolgozása rendben van';

    private const CONSEQUENCE = 'Amíg a futtatás áll, nem mennek ki a levelek, és késnek az időhöz kötött teendők. '
        . 'A webtárhely ütemezőjének percenként meg kell hívnia a wp-cron.php-t.</p>';

    public function set_up(): void
    {
        parent::set_up();

        remove_all_actions('admin_notices');
        remove_all_filters('site_status_tests');
        (new PeriodicRunHealth($this->clock))->register();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
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
        $test = $tests['direct'][PeriodicRunHealth::SITE_HEALTH_TEST] ?? null;
        self::assertIsArray($test);
        self::assertIsCallable($test['test']);

        return ($test['test'])();
    }

    /** @return array<string, mixed> the Site Health result with the given label, status and description */
    private static function siteHealthResult(string $label, string $status, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => 'Kaláka', 'color' => 'blue'],
            'description' => $description,
            'actions' => '',
            'test' => 'kalaka_periodic_run',
        ];
    }

    private function lastRunWas(string $utc): void
    {
        update_option(PeriodicRun::LAST_RUN_OPTION, $utc, false);
    }

    public function testARunExactlyTwentyMinutesOldAndAYoungerOneShowNothing(): void
    {
        $this->lastRunWas('2026-11-30 08:40:00.000000');
        self::assertSame('', $this->notices());

        $this->lastRunWas('2026-11-30 08:59:00.000000');
        self::assertSame('', $this->notices());
    }

    public function testARunJustOverTwentyMinutesOldShowsTheLateNoticeWithTheTimeInTheSitesZone(): void
    {
        $this->lastRunWas('2026-11-30 08:39:59.999999');

        self::assertSame(
            '<div class="notice notice-error"><p><strong>' . self::LATE_LABEL . '</strong></p>'
            . '<p>Az utolsó befejezett futtatás: 2026. 11. 30. 09:39. '
            . self::CONSEQUENCE . '</div>',
            $this->notices()
        );
    }

    public function testNoStoredRunAndAValueThatDoesNotParseShowTheNeverNotice(): void
    {
        $never = '<div class="notice notice-error"><p><strong>' . self::NEVER_LABEL . '</strong></p>'
            . '<p>' . self::CONSEQUENCE . '</div>';

        delete_option(PeriodicRun::LAST_RUN_OPTION);
        self::assertSame($never, $this->notices());

        $this->lastRunWas('x');
        self::assertSame($never, $this->notices());
    }

    public function testAClosedSiteAndAHeldLockDoNotChangeTheCheck(): void
    {
        $this->lastRunWas('2026-11-30 08:30:00.000000');

        $this->closeTheSite();
        self::assertStringContainsString(self::LATE_LABEL, $this->notices());

        update_option('kalaka_periodic_run.lock', '2026-11-30 08:59:00', false);
        self::assertStringContainsString(self::LATE_LABEL, $this->notices());
    }

    public function testTheCheckWritesNothing(): void
    {
        (new PeriodicSchedule($this->clock))->register(static fn (): null => null);
        $crons = _get_cron_array();

        foreach (['2026-11-30 08:30:00.000000', null] as $stored) {
            if ($stored === null) {
                delete_option(PeriodicRun::LAST_RUN_OPTION);
            } else {
                $this->lastRunWas($stored);
            }
            $options = $this->kalakaOptions();

            self::assertNotSame('', $this->notices());
            self::assertSame('critical', $this->siteHealth()['status']);

            self::assertSame($options, $this->kalakaOptions());
            self::assertSame($crons, _get_cron_array());
        }
    }

    public function testAUserWithoutTheSiteHealthCapabilitySeesNothingAndReadsNoOption(): void
    {
        $this->lastRunWas('2026-11-30 08:30:00.000000');
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $reads = 0;
        add_filter('pre_option_' . PeriodicRun::LAST_RUN_OPTION, static function (mixed $pre) use (&$reads): mixed {
            ++$reads;

            return $pre;
        });

        self::assertSame('', $this->notices());
        self::assertSame(0, $reads);
    }

    public function testARunFiveMinutesOldShowsNothing(): void
    {
        $this->lastRunWas('2026-11-30 08:55:00.000000');

        self::assertSame('', $this->notices());
    }

    public function testTheCurrentTimeComesFromTheClock(): void
    {
        $this->lastRunWas('2026-11-30 08:55:00.000000');
        $this->useClock('2026-11-30 10:30:00');
        remove_all_actions('admin_notices');
        (new PeriodicRunHealth($this->clock))->register();

        self::assertStringContainsString(self::LATE_LABEL, $this->notices());
    }

    public function testTheSiteHealthTestPassesUpToTwentyMinutesWithTheLastRunInTheSitesZone(): void
    {
        $this->lastRunWas('2026-11-30 08:40:00.000000');

        self::assertSame(
            self::siteHealthResult(self::GOOD_LABEL, 'good', '<p>Az utolsó befejezett futtatás: 2026. 11. 30. 09:40.</p>'),
            $this->siteHealth()
        );
    }

    public function testTheSiteHealthTestIsCriticalWithTheNoticesTextsWhenLateOrNever(): void
    {
        $this->lastRunWas('2026-11-30 08:39:59.999999');
        self::assertSame(
            self::siteHealthResult(
                self::LATE_LABEL,
                'critical',
                '<p>Az utolsó befejezett futtatás: 2026. 11. 30. 09:39. ' . self::CONSEQUENCE
            ),
            $this->siteHealth()
        );

        delete_option(PeriodicRun::LAST_RUN_OPTION);
        self::assertSame(
            self::siteHealthResult(self::NEVER_LABEL, 'critical', '<p>' . self::CONSEQUENCE),
            $this->siteHealth()
        );
    }

    public function testTheSiteHealthTestRunsWithoutAUserForTheWeeklyCronCheck(): void
    {
        wp_set_current_user(0);
        $this->lastRunWas('2026-11-30 08:55:00.000000');

        self::assertSame('good', $this->siteHealth()['status']);
    }

    public function testBootRegistersTheNoticeAndTheSiteHealthTest(): void
    {
        remove_all_actions('admin_notices');
        remove_all_filters('site_status_tests');
        remove_all_actions('kalaka_periodic_run');
        remove_all_actions('kalaka_periodic_run_now');
        delete_option(PeriodicRun::LAST_RUN_OPTION);

        (new \Kalaka\Foundation\Module())->boot();

        self::assertStringContainsString(self::NEVER_LABEL, $this->notices());
        self::assertSame(self::NEVER_LABEL, $this->siteHealth()['label']);
    }

    public function testAFrontEndRequestSpawnsWpCronWhenTheRecurringEventIsDue(): void
    {
        (new PeriodicSchedule($this->clock))->register(static fn (): null => null);
        wp_unschedule_hook(PeriodicSchedule::HOOK);
        wp_schedule_event(time() - 60, PeriodicSchedule::INTERVAL, PeriodicSchedule::HOOK);
        delete_transient('doing_cron');
        $urls = [];
        add_filter('pre_http_request', static function (mixed $pre, array $args, string $url) use (&$urls): array {
            $urls[] = $url;

            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }, 10, 3);

        // The test bootstrap defines DISABLE_WP_CRON, which makes wp_cron() spawn nothing, so this
        // calls spawn_cron(), the function wp_cron() ends in for a due event.
        self::assertArrayHasKey(PeriodicSchedule::HOOK, current(wp_get_ready_cron_jobs()) ?: []);
        spawn_cron();

        self::assertCount(1, $urls);
        self::assertStringContainsString('/wp-cron.php', $urls[0]);
        self::assertStringContainsString('doing_wp_cron', $urls[0]);
    }

    /** @return array<string, string> the plugin's options, by name */
    private function kalakaOptions(): array
    {
        global $wpdb;

        $options = [];
        foreach ($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'kalaka\\_%'") as $row) {
            $options[$row->option_name] = $row->option_value;
        }
        ksort($options);

        return $options;
    }
}
