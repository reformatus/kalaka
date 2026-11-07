<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Database;

/**
 * The database's health check: an admin notice and a Site Health test, both critical while
 * `BinlogCheck` refuses, that is while every use case is refused. It only reads `BinlogCheck`; it
 * writes nothing. foundation.md, "The use-case runner".
 */
final class IsolationHealth
{
    public const SITE_HEALTH_TEST = 'kalaka_database_isolation';

    private const CRITICAL_LABEL = 'Az adatbázis beállítása miatt a Kaláka nem tud adatot menteni!';

    private const CRITICAL_DESCRIPTION = 'A webtárhely adatbázisszerverén be van kapcsolva a bináris naplózás, és a '
        . 'binlog_format értéke STATEMENT. Ilyenkor a Kaláka minden mentést visszautasít. A tárhely üzemeltetőjének '
        . 'a binlog_format értékét MIXED-re vagy ROW-ra kell állítania.';

    private const GOOD_LABEL = 'Az adatbázis beállítása megfelel a Kalákának';

    private const GOOD_DESCRIPTION = 'A webtárhely adatbázisszervere engedi, hogy a Kaláka minden mentést '
        . 'READ COMMITTED elkülönítési szinten végezzen.';

    public function __construct(private readonly BinlogCheck $binlog)
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
        if ($check['critical']) {
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
            'status' => $check['critical'] ? 'critical' : 'good',
            'badge' => ['label' => 'Kaláka', 'color' => 'blue'],
            'description' => $check['description'],
            'actions' => '',
            'test' => self::SITE_HEALTH_TEST,
        ];
    }

    /** @return array{critical: bool, label: string, description: string} the description is HTML */
    private function check(): array
    {
        if ($this->binlog->refuses()) {
            return [
                'critical' => true,
                'label' => self::CRITICAL_LABEL,
                'description' => '<p>' . self::CRITICAL_DESCRIPTION . '</p>',
            ];
        }

        return [
            'critical' => false,
            'label' => self::GOOD_LABEL,
            'description' => '<p>' . self::GOOD_DESCRIPTION . '</p>',
        ];
    }
}
