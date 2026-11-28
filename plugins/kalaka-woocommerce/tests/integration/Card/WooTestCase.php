<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Card;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Pricing\ConfirmationTestCase;
use Kalaka\WooCommerce\Module;

/**
 * The base of the card payment tests: core's confirmation case (an event with a fixed price, registrations, a
 * runner with the money phase), WooCommerce set to forints, the stand-in gateway, and one of WooCommerce's two
 * order-storage modes, which the concrete class names.
 */
abstract class WooTestCase extends ConfirmationTestCase
{
    private Runner $production;

    private static bool $tables = false;

    /** Whether orders live in WooCommerce's own tables (HPOS) rather than in posts. */
    abstract protected function usesHpos(): bool;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        // Outside the test's transaction: creating a table commits.
        if (!self::$tables) {
            \WC_Install::create_tables();
            wc_get_container()->get(DataSynchronizer::class)->create_database_tables();
            self::$tables = true;
        }
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->production = Runner::current();
        Runner::publish($this->runner);
        update_option('woocommerce_currency', 'HUF');
        update_option('woocommerce_calc_taxes', 'no');
        update_option('woocommerce_price_num_decimals', 0);
        update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
        update_option(
            CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION,
            $this->usesHpos() ? 'yes' : 'no'
        );
        self::assertSame($this->usesHpos(), OrderUtil::custom_orders_table_usage_is_enabled());
        add_filter('woocommerce_payment_gateways', [self::class, 'gateways']);
        WC()->payment_gateways()->init();
        StandInGateway::$outcome = 'paid';

        // The module as core's boot built it: its hooks and its contributions.
        $contributions = new Contributions();
        (new Module())->contribute($contributions);
        Contributions::record($contributions);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        remove_filter('woocommerce_payment_gateways', [self::class, 'gateways']);
        Runner::publish($this->production);

        parent::tear_down();
    }

    /**
     * @internal the `woocommerce_payment_gateways` filter
     * @param array<int, mixed> $gateways
     * @return array<int, mixed>
     */
    public static function gateways(array $gateways): array
    {
        $gateways[] = StandInGateway::class;

        return $gateways;
    }

    /** A price for the event and a registered registration that owes it. */
    protected function owing(string $code, string $price = '10000'): RegistrationKey
    {
        $this->setPrice($price);

        return $this->register($code);
    }
}
