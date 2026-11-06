<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Foundation\Api\Clock;

/**
 * The periodic run's health check: an admin notice and a Site Health test, both late while the last
 * completed run is older than 20 minutes or there has been none. It only reads the last-run option
 * and the `Clock`; it writes nothing and never schedules, starts or unlocks a run.
 * foundation.md, "Deferred work".
 */
final class PeriodicRunHealth
{
    /** Longer than `PeriodicRun::LOCK_TTL` (900), so a lock left by a crash is taken over before the warning shows. */
    private const MAX_AGE_SECONDS = 1200;

    public const SITE_HEALTH_TEST = 'kalaka_periodic_run';

    private const GOOD_LABEL = 'Az időzített események feldolgozása rendben van';

    private const LATE_LABEL = 'Az időzített események feldolgozása több mint 20 perce megakadt!';

    private const NEVER_LABEL = 'Az időzített események feldolgozása nem működik!';

    private const LAST_RUN = 'Az utolsó befejezett futtatás: %s.';

    private const CONSEQUENCE = 'Amíg a futtatás áll, nem mennek ki a levelek, és késnek az időhöz kötött teendők. '
        . 'A webtárhely ütemezőjének percenként meg kell hívnia a wp-cron.php-t.';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function register(): void
    {
        add_action('admin_notices', $this->notice(...));
        add_filter('site_status_tests', $this->addSiteHealthTest(...));
    }

    private function notice(): void
    {
        if (!current_user_can('view_site_health_checks')) {
            return;
        }

        $check = $this->check();
        if ($check['late']) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html($check['label']) . '</strong></p>'
                . wp_kses_post($check['description']) . '</div>';
        }
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    private function addSiteHealthTest(array $tests): array
    {
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        $direct[self::SITE_HEALTH_TEST] = [
            'label' => self::GOOD_LABEL,
            'test' => $this->siteHealthResult(...),
        ];
        $tests['direct'] = $direct;

        return $tests;
    }

    /**
     * The Site Health result. WordPress gates the Site Health screen with `view_site_health_checks`
     * and also runs direct tests from its weekly WP-Cron check, where there is no user.
     *
     * @return array<string, string|array<string, string>> label, status, badge, description, actions, test
     */
    private function siteHealthResult(): array
    {
        $check = $this->check();

        return [
            'label' => $check['label'],
            'status' => $check['late'] ? 'critical' : 'good',
            'badge' => ['label' => 'Kaláka', 'color' => 'blue'],
            'description' => $check['description'],
            'actions' => '',
            'test' => self::SITE_HEALTH_TEST,
        ];
    }

    /** @return array{late: bool, label: string, description: string} the description is HTML */
    private function check(): array
    {
        $stored = get_option(PeriodicRun::LAST_RUN_OPTION);
        $lastRun = is_string($stored) ? PeriodicRun::parseLastRun($stored) : null;

        if ($lastRun === null) {
            return ['late' => true, 'label' => self::NEVER_LABEL, 'description' => '<p>' . self::CONSEQUENCE . '</p>'];
        }

        $lastRunText = sprintf(self::LAST_RUN, esc_html((string) wp_date('Y. m. d. H:i', $lastRun->getTimestamp())));

        if ($this->clock->now() <= $lastRun->modify('+' . self::MAX_AGE_SECONDS . ' seconds')) {
            return ['late' => false, 'label' => self::GOOD_LABEL, 'description' => '<p>' . $lastRunText . '</p>'];
        }

        return [
            'late' => true,
            'label' => self::LATE_LABEL,
            'description' => '<p>' . $lastRunText . ' ' . self::CONSEQUENCE . '</p>',
        ];
    }
}
