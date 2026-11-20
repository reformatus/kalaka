<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

final class Module extends AbstractModule
{
    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(private readonly AdminAccessGate $gate = new AdminAccessGate())
    {
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
     * this filter the „Jelentkezők” grid is unreachable on a WooCommerce site.
     */
    public function boot(): void
    {
        add_filter('woocommerce_prevent_admin_access', [$this->gate, 'prevent']);
    }
}
