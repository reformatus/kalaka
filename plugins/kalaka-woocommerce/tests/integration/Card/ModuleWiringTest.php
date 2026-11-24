<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\WooCommerce\HiddenProduct;
use Kalaka\WooCommerce\Module;
use Kalaka\WooCommerce\PaymentReporter;
use Kalaka\WooCommerce\ReconcileStep;
use Kalaka\WooCommerce\ReturnAddress;
use Kalaka\WooCommerce\WooProvider;
use WP_UnitTestCase;

/** What the module contributes to core and hooks into WooCommerce. */
final class ModuleWiringTest extends WP_UnitTestCase
{
    public function testItContributesTheProviderAndTheReconcileStep(): void
    {
        $contributions = new Contributions();

        (new Module())->contribute($contributions);

        self::assertCount(1, $contributions->paymentProviders());
        self::assertInstanceOf(WooProvider::class, $contributions->paymentProviders()[0]);
        self::assertSame('woocommerce', $contributions->paymentProviders()[0]->id());
        $steps = $contributions->periodicSteps();
        self::assertCount(1, $steps);
        self::assertInstanceOf(ReconcileStep::class, $steps[0]);
    }

    public function testBootHooksTheModulesOwnCallbacksOnWooCommercesPaidRefundedAndReturnEvents(): void
    {
        $reporter = new PaymentReporter();
        $return = new ReturnAddress();
        $product = new HiddenProduct();
        $module = new Module(reporter: $reporter, returnAddress: $return, product: $product);

        $module->boot();

        // WooCommerce hooks several of these names itself, so each callback is asked for by name.
        foreach (
            [
                ['woocommerce_payment_complete', [$module, 'paid']],
                ['woocommerce_order_status_processing', [$module, 'paid']],
                ['woocommerce_order_status_completed', [$module, 'paid']],
                ['woocommerce_order_refunded', [$reporter, 'recordRefund']],
                ['woocommerce_order_status_changed', [$reporter, 'statusChanged']],
                ['woocommerce_trash_order', [$reporter, 'trashed']],
                ['woocommerce_get_return_url', [$return, 'of']],
                ['woocommerce_is_purchasable', [$product, 'purchasable']],
            ] as [$hook, $callback]
        ) {
            self::assertNotFalse(has_filter($hook, $callback), $hook . ' has no callback of the module.');
        }
    }
}
