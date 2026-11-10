<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\SiteWarning;

use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Tests\Integration\Foundation\RunnerTestCase;

/**
 * The base of the site warning tests: no banner or Site Health test registered but the test's own,
 * an administrator logged in, and the load state restored afterwards.
 */
abstract class SiteWarningTestCase extends RunnerTestCase
{
    /** @var list<LoadFailure> */
    private array $loadFailures = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->loadFailures = LoadState::current()->failures;
        remove_all_actions('admin_notices');
        remove_all_filters('site_status_tests');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        LoadState::record($this->loadFailures);

        parent::tear_down();
    }

    /** What `admin_notices` prints. */
    protected function notices(): string
    {
        ob_start();
        do_action('admin_notices');

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> the Site Health tests registered on `site_status_tests`, direct ones */
    protected function siteHealthTests(): array
    {
        $tests = apply_filters('site_status_tests', ['direct' => [], 'async' => []]);
        self::assertIsArray($tests);
        self::assertIsArray($tests['direct']);

        return $tests['direct'];
    }

    /** @return array<string, mixed> what the Site Health test returns */
    protected function siteHealth(string $testId): array
    {
        $test = $this->siteHealthTests()[$testId] ?? null;
        self::assertIsArray($test);
        self::assertIsCallable($test['test']);

        return ($test['test'])();
    }

    /** @return array<string, mixed> a Site Health result */
    protected static function result(string $testId, string $label, string $status, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => 'Kaláka', 'color' => 'blue'],
            'description' => $description,
            'actions' => '',
            'test' => $testId,
        ];
    }

    protected static function banner(string $class, string $title, string $body): string
    {
        return '<div class="notice ' . $class . '"><p><strong>' . $title . '</strong></p>' . $body . '</div>';
    }

    protected function closeTheSite(): void
    {
        LoadState::record([new LoadFailure('x', 'y')]);
    }

    /** @return list<string> the log's lines, without PHP's time stamps */
    protected static function logLines(string $log): array
    {
        return array_values(array_map(
            static fn (string $line): string => (string) preg_replace('/^\[[^\]]*\] /', '', $line),
            array_filter(explode("\n", trim($log)))
        ));
    }
}
