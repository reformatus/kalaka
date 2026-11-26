<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\WooCommerce\Cli\CardBillingCommand;
use Kalaka\WooCommerce\Cli\CardSurchargeCommand;

final class Module extends AbstractModule
{
    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(
        private readonly AdminAccessGate $gate = new AdminAccessGate(),
        private readonly WooProvider $provider = new WooProvider(billing: new DeclaredFieldsBilling()),
        private readonly PaymentReporter $reporter = new PaymentReporter(),
        private readonly ReconcileStep $reconcile = new ReconcileStep(),
        private readonly ReturnAddress $returnAddress = new ReturnAddress(),
        private readonly HiddenProduct $product = new HiddenProduct(),
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

    /** The payments port (money.md, "Payments") on WooCommerce orders, and the step that catches a lost report. */
    public function contribute(Contributions $to): void
    {
        $to->paymentProvider($this->provider);
        $to->periodicStep($this->reconcile);
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

        // A paid order is recorded by one function whichever of the three hooks announces it first.
        add_action('woocommerce_payment_complete', [$this, 'paid'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'paid'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'paid'], 10, 1);
        add_action('woocommerce_order_refunded', [$this->reporter, 'recordRefund'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this->reporter, 'statusChanged'], 10, 3);
        add_action('woocommerce_trash_order', [$this->reporter, 'trashed'], 10, 1);
        add_filter('woocommerce_get_return_url', [$this->returnAddress, 'of'], 10, 2);
        add_filter('woocommerce_is_purchasable', [$this->product, 'purchasable'], 10, 2);
        if (defined('WP_CLI') && WP_CLI) {
            CardSurchargeCommand::register();
            CardBillingCommand::register();
        }
    }

    /**
     * @internal the three paid hooks
     * @param mixed $orderId
     */
    public function paid($orderId): void
    {
        $this->reporter->recordPaidIfPaid((int) $orderId);
    }
}
