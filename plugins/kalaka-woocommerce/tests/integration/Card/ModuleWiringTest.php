<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\WooCommerce\Module;
use Kalaka\WooCommerce\ReconcileStep;
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

    public function testItHooksWooCommercesPaidRefundedAndReturnEvents(): void
    {
        foreach (
            [
                'woocommerce_payment_complete',
                'woocommerce_order_status_processing',
                'woocommerce_order_status_completed',
                'woocommerce_order_refunded',
                'woocommerce_order_status_changed',
                'woocommerce_get_return_url',
            ] as $hook
        ) {
            self::assertNotFalse(has_filter($hook), $hook . ' is not hooked.');
        }
    }
}
