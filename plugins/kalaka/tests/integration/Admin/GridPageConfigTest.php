<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grid\GridPage;

/**
 * S38: the grid page hands the app the site's time zone, which the detail panel shows its dates and today in.
 */
final class GridPageConfigTest extends AdminTestCase
{
    /** @var array<string, mixed> */
    private array $get;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->get = $_GET;
        wp_set_current_user($this->admin->userId);
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        $_GET = $this->get;
        wp_set_current_user(0);
        Access::forget();
        wp_scripts()->remove('kalaka-admin-grid');

        parent::tear_down();
    }

    /** The configuration the page printed for the app, as the app reads it. */
    private function config(int $event): array
    {
        $_GET['page'] = GridPage::slug($event);
        GridPage::enqueue('toplevel_page_' . GridPage::slug($event));
        ob_start();
        GridPage::render();
        ob_end_clean();
        $inline = wp_scripts()->get_data('kalaka-admin-grid', 'before');
        self::assertIsArray($inline);
        $script = (string) end($inline);
        self::assertStringStartsWith('window.kalakaGrid = ', $script);
        $config = json_decode(substr($script, strlen('window.kalakaGrid = '), -1), true);
        self::assertIsArray($config);

        return $config;
    }

    public function testTheConfigurationCarriesTheSiteTimeZoneByName(): void
    {
        update_option('timezone_string', 'Europe/Budapest');

        self::assertSame('Europe/Budapest', $this->config($this->createEvent())['timezone']);
    }

    public function testTheConfigurationCarriesAnOffsetZoneAsAnOffset(): void
    {
        update_option('timezone_string', '');
        update_option('gmt_offset', '1');

        self::assertSame('+01:00', $this->config($this->createEvent())['timezone']);
    }
}
