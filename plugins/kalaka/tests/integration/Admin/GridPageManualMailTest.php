<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grid\GridPage;
use Kalaka\Foundation\Api\Module\ActionCatalogue;

/**
 * The grid page's configuration tells the app, per tab, whether the tab carries the manual mail (the grid offers the
 * bulk action „Levél küldése” by it; the ability and the preview decide again on every call): always for full
 * access, and for a grant holder only on the tab of a grant that carries `kalaka_manual_mail`.
 */
final class GridPageManualMailTest extends GrantTestCase
{
    private const MANUAL_MAIL = 'kalaka_manual_mail';

    /** @var array<string, mixed> */
    private array $get;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        ActionCatalogue::record(new ActionCatalogue([self::EDIT, self::MANUAL_MAIL]));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saves the request's own state
        $this->get = $_GET;
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

    /**
     * The tabs' `manualMail` the page printed for the app, in tab order, for the user.
     *
     * @return list<bool>
     */
    private function manualMailTabs(int $user, int $event): array
    {
        wp_set_current_user($user);
        Access::forget();
        $_GET['page'] = GridPage::slug($event);
        GridPage::enqueue('toplevel_page_' . GridPage::slug($event));
        ob_start();
        GridPage::render();
        ob_end_clean();
        $inline = wp_scripts()->get_data('kalaka-admin-grid', 'before');
        self::assertIsArray($inline);
        $script = (string) end($inline);
        $config = json_decode(substr($script, strlen('window.kalakaGrid = '), -1), true);
        self::assertIsArray($config);

        return array_map(static fn (array $tab): bool => $tab['manualMail'], $config['tabs']);
    }

    public function testFullAccessCarriesItOnEveryTab(): void
    {
        $event = $this->createEvent();
        $this->seedMaster($event, []);
        $this->plainView($event);

        self::assertSame([true, true, true], $this->manualMailTabs($this->admin->userId, $event));
    }

    public function testAGrantHolderHasItOnlyOnTheTabOfAGrantThatCarriesIt(): void
    {
        $event = $this->createEvent();
        $view = $this->plainView($event);
        $user = $this->plainUser();
        $this->saveGrant($event, $user, ['view_id' => $view, 'actions' => [self::EDIT]]);
        $this->saveGrant($event, $user, ['view_id' => $view, 'actions' => [self::MANUAL_MAIL]]);

        self::assertSame([false, true], $this->manualMailTabs($user, $event));
    }
}
