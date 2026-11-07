<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

/**
 * The shape every Kaláka health check shares: a direct Site Health test with the Kaláka badge, and
 * an admin notice for users who may see Site Health, shown only while the check is critical. A
 * subclass gives its test id, its passed label and the check itself; it writes nothing.
 */
abstract class HealthCheck
{
    private const BADGE = ['label' => 'Kaláka', 'color' => 'blue'];

    /** The id of the Site Health test. */
    abstract protected function testId(): string;

    /** The label WordPress lists the test under, which is the passed result's label. */
    abstract protected function goodLabel(): string;

    /** @return array{critical: bool, label: string, description: string} the description is HTML */
    abstract protected function check(): array;

    final public function register(): void
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
        $direct[$this->testId()] = [
            'label' => $this->goodLabel(),
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
            'badge' => self::BADGE,
            'description' => $check['description'],
            'actions' => '',
            'test' => $this->testId(),
        ];
    }
}
