<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

final class Module extends AbstractModule
{
    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(
        private readonly AdminAccessGate $gate = new AdminAccessGate(),
        private readonly LoginRedirects $login = new LoginRedirects(),
    ) {
    }

    public function id(): string
    {
        return 'woocommerce';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function contribute(Contributions $to): void
    {
    }

    /**
     * WooCommerce sends every user without `edit_posts` or `manage_woocommerce` from wp-admin to My
     * Account (`WC_Admin::prevent_admin_access`). A grant holder has neither capability, so without
     * this filter the „Jelentkezők” grid is unreachable on a WooCommerce site. WooCommerce also hides
     * the front-end admin bar from them (`wc_disable_admin_bar`, switched by
     * `woocommerce_disable_admin_bar`), so the same user keeps it.
     */
    public function boot(): void
    {
        add_filter('woocommerce_prevent_admin_access', [$this->gate, 'prevent']);
        add_filter('woocommerce_disable_admin_bar', [$this->gate, 'keepAdminBar']);

        // One login and one password screen: WordPress's own. After WooCommerce's handlers (priority 10).
        $this->login->removeLostPasswordOverride();
        add_action('template_redirect', [$this->login, 'redirect'], 20);
    }
}
