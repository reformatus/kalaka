<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grid\GridPage;
use Kalaka\Admin\Mail\MailPages;

/**
 * The mail screens' pages (prototype): the event's „Levelek” submenu item and the site's „Levelezés” page exist for
 * full access only, and each page prints the configuration its app reads.
 */
final class MailPagesTest extends GrantTestCase
{
    /** @var array<string, mixed> */
    private array $get;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saves the request's own state
        $this->get = $_GET;
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        Access::forget();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        $_GET = $this->get;
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        wp_set_current_user(0);
        Access::forget();
        wp_scripts()->remove('kalaka-admin-mail');

        parent::tear_down();
    }

    /** @return list<string> the submenu slugs under the parent */
    private function submenu(string $parent): array
    {
        $items = $GLOBALS['submenu'][$parent] ?? [];

        return array_map(static fn (array $item): string => (string) $item[2], $items);
    }

    public function testFullAccessGetsTheSitePageAndTheEventsMailPage(): void
    {
        $event = $this->createEvent();
        wp_set_current_user($this->admin->userId);
        Access::forget();

        MailPages::menu();

        self::assertSame(['kalaka-mail'], $this->submenu('kalaka-mail'));
        // After core's separator (4) and before the events (4.01 and up), drawn by the CSS mask, not by an icon URL.
        self::assertSame('kalaka-mail', $GLOBALS['menu']['4.001'][2]);
        self::assertSame('none', $GLOBALS['menu']['4.001'][6]);
        self::assertContains(sprintf('kalaka-event-%d-mail', $event), $this->submenu(GridPage::slug($event)));
    }

    public function testAGrantHolderGetsNeitherPage(): void
    {
        $event = $this->createEvent();
        $user = $this->plainUser();
        $this->saveGrant($event, $user);
        wp_set_current_user($user);
        Access::forget();

        MailPages::menu();

        self::assertSame([], $GLOBALS['submenu']);
    }

    /**
     * The configuration the page printed for its script: `window.kalakaMail` (the site page's app) or
     * `window.kalakaMailPage` (the event page's dynamic parts).
     */
    private function config(string $page, string $global = 'kalakaMail'): array
    {
        $_GET['page'] = $page;
        MailPages::enqueue();
        ob_start();
        MailPages::render();
        ob_end_clean();
        $inline = wp_scripts()->get_data('kalaka-admin-mail', 'before');
        self::assertIsArray($inline);
        $script = (string) end($inline);
        $prefix = 'window.' . $global . ' = ';
        self::assertStringStartsWith($prefix, $script);
        $config = json_decode(substr($script, strlen($prefix), -1), true);
        self::assertIsArray($config);

        return $config;
    }

    public function testTheEventPageCarriesItsEventAndTheRestBase(): void
    {
        $event = $this->createEvent('Nyári tábor');
        wp_set_current_user($this->admin->userId);
        Access::forget();

        $config = $this->config(sprintf('kalaka-event-%d-mail', $event), 'kalakaMailPage');

        self::assertSame($event, $config['event']);
        self::assertSame('/kalaka-admin/v1', $config['rest']);
        self::assertSame([], $config['batches']);
        self::assertStringContainsString('view=layout', $config['urls']['layout']);
    }

    public function testTheSitePageNamesTheEventsItCanLink(): void
    {
        $event = $this->createEvent('Nyári tábor');
        wp_set_current_user($this->admin->userId);
        Access::forget();

        $config = $this->config('kalaka-mail');

        self::assertSame('sender', $config['kind']);
        self::assertContains($event, array_column($config['events'], 'id'));
    }

    /** The site page's HTML, as `render()` prints it. */
    private function sitePage(): string
    {
        $_GET['page'] = 'kalaka-mail';
        MailPages::enqueue();
        ob_start();
        MailPages::render();

        return (string) ob_get_clean();
    }

    public function testTheSitePagePrintsTheBudgetAsCoresSettingsFormAfterTheAppsMount(): void
    {
        wp_set_current_user($this->admin->userId);
        Access::forget();
        \Kalaka\Mail\Pause\BudgetSetting::register();

        $html = $this->sitePage();

        self::assertStringContainsString('<form method="post" action="options.php">', $html);
        // Core's `settings_fields()` prints this field with single quotes, the others with double ones.
        self::assertMatchesRegularExpression('/name=["\']option_page["\'] value=["\']kalaka_mail["\']/', $html);
        self::assertStringContainsString('<table class="form-table"', $html);
        self::assertMatchesRegularExpression('/<label for="kalaka_mail_hourly_budget">[^<]+<\/label>/', $html);
        self::assertStringContainsString('name="kalaka_mail_hourly_budget"', $html);
        self::assertStringContainsString('Alapértelmezés: 240', $html);
        self::assertLessThan(strpos($html, 'action="options.php"'), strpos($html, 'id="kalaka-mail"'));
    }

    public function testTheEventPageHasNoSettingsForm(): void
    {
        $event = $this->createEvent();
        wp_set_current_user($this->admin->userId);
        Access::forget();

        $_GET['page'] = sprintf('kalaka-event-%d-mail', $event);
        MailPages::enqueue();
        ob_start();
        MailPages::render();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('options.php', $html);
    }
}
