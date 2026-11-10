<?php

declare(strict_types=1);

namespace Kalaka\Foundation\SiteWarning;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\SiteWarning\Severity;
use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/**
 * The site's warnings, the one banner renderer and the one Site Health adapter: foundation.md,
 * "Failures", "Site warnings". The boot registers it before any module, with its own warnings
 * first, and adds the contributed ones after the modules contributed.
 *
 * @internal
 */
final class SiteWarnings
{
    private const BADGE = ['label' => 'Kaláka', 'color' => 'blue'];

    private const SUMMARY = 'Részletek';

    /** @var list<SiteWarning> */
    private array $warnings = [];

    public function add(SiteWarning $warning): void
    {
        $this->warnings[] = $warning;
    }

    public function register(): void
    {
        add_action('admin_notices', $this->printBanners(...));
        add_filter('site_status_tests', $this->addSiteHealthTests(...));
    }

    /**
     * The banners of every admin page, for users who may see Site Health and users with full access;
     * while the load failed Admin's access check is not available, so only the former. Errors first,
     * then info, each in the order added. A banner cannot be dismissed.
     */
    private function printBanners(): void
    {
        if (!self::mayView()) {
            return;
        }

        $shown = [];
        foreach ($this->warnings as $warning) {
            $result = self::checked($warning);
            if ($result !== null) {
                $shown[] = $result;
            }
        }
        usort(
            $shown,
            static fn (Shown $a, Shown $b): int => self::rank($a->severity) <=> self::rank($b->severity)
        );

        foreach ($shown as $result) {
            echo wp_kses_post(self::banner($result));
        }
    }

    private static function mayView(): bool
    {
        if (current_user_can('view_site_health_checks')) {
            return true;
        }

        return LoadState::current()->isOpen() && current_user_can(AdminAccess::FULL_ACCESS);
    }

    private static function rank(Severity $severity): int
    {
        return $severity === Severity::Error ? 0 : 1;
    }

    /** The warning's check, through the boundary: one that throws shows no banner and is logged. */
    private static function checked(SiteWarning $warning): ?Shown
    {
        $result = null;
        Boundary::background(self::what($warning), static function () use ($warning, &$result): void {
            $result = $warning->check();
        });

        return $result;
    }

    private static function banner(Shown $shown): string
    {
        $class = $shown->severity === Severity::Error ? 'notice-error' : 'notice-info';

        return '<div class="notice ' . $class . '"><p><strong>' . esc_html($shown->title) . '</strong></p>'
            . self::body($shown, false) . '</div>';
    }

    /** The description, then the details, collapsed in a banner and open in Site Health. */
    private static function body(Shown $shown, bool $open): string
    {
        $html = '<p>' . esc_html($shown->description) . '</p>';
        if ($shown->details !== null && $shown->details !== '') {
            $lines = array_map('esc_html', preg_split('/\R/u', $shown->details) ?: []);
            $html .= '<details' . ($open ? ' open' : '') . '><summary>' . self::SUMMARY . '</summary><p>'
                . implode('<br>', $lines) . '</p></details>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    private function addSiteHealthTests(array $tests): array
    {
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        foreach ($this->warnings as $warning) {
            $health = self::siteHealthOf($warning);
            if ($health === null) {
                continue;
            }
            $direct[$health->testId] = [
                'label' => $health->goodLabel,
                'test' => static fn (): array => self::siteHealthResult($warning, $health),
            ];
        }
        $tests['direct'] = $direct;

        return $tests;
    }

    private static function siteHealthOf(SiteWarning $warning): ?SiteHealth
    {
        $health = null;
        Boundary::background(self::what($warning), static function () use ($warning, &$health): void {
            $health = $warning->siteHealth();
        });

        return $health;
    }

    /**
     * The Site Health result. WordPress gates the Site Health screen with `view_site_health_checks`
     * and also runs direct tests from its weekly WP-Cron check, where there is no user. A check that
     * throws answers critical with the boundary's error text.
     *
     * @return array<string, string|array<string, string>> label, status, badge, description, actions, test
     */
    private static function siteHealthResult(SiteWarning $warning, SiteHealth $registered): array
    {
        try {
            $shown = $warning->check();
            $health = $warning->siteHealth() ?? $registered;
        } catch (\Throwable $e) {
            $lines = explode("\n", Boundary::shown(self::what($warning), $e)->displayMessage(), 2);

            return self::result(
                $registered->testId,
                $lines[0],
                'critical',
                isset($lines[1]) ? '<p>' . esc_html($lines[1]) . '</p>' : ''
            );
        }

        if ($shown === null) {
            $description = $health->goodDescription === '' ? '' : '<p>' . esc_html($health->goodDescription) . '</p>';

            return self::result($health->testId, $health->goodLabel, 'good', $description);
        }

        return self::result(
            $health->testId,
            $shown->title,
            $shown->severity === Severity::Error ? 'critical' : 'recommended',
            self::body($shown, true)
        );
    }

    /** @return array<string, string|array<string, string>> */
    private static function result(string $testId, string $label, string $status, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => self::BADGE,
            'description' => $description,
            'actions' => '',
            'test' => $testId,
        ];
    }

    private static function what(SiteWarning $warning): string
    {
        return 'site warning ' . $warning->id();
    }
}
