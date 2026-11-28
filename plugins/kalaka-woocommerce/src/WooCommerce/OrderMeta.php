<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

/**
 * The names this plugin stores on WooCommerce orders and items (through WooCommerce's own CRUD, so both
 * order-storage modes work), and its one option. Item meta starts with an underscore, so WooCommerce hides
 * it from the order display.
 */
final class OrderMeta
{
    /** Order meta: marks an order of this plugin; the value is the plugin's meta version. */
    public const MARKER = '_kalaka';

    /** Order meta, one row per request line: the registration as `12:47K3`. Found by `wc_get_orders()`. */
    public const REGISTRATION = '_kalaka_registration';

    /** Order meta: where the registrant returns to after paying (a same-site address). */
    public const RETURN_URL = '_kalaka_return_url';

    /** Order meta: set once the paid order was recorded in core's ledger. */
    public const RECORDED = '_kalaka_recorded';

    /** Item meta: the registration as `12:47K3`. */
    public const ITEM_REGISTRATION = '_kalaka_registration';

    /** Item meta: the payer's ID, always `participant` here. */
    public const ITEM_PAYER = '_kalaka_payer';

    /** The option holding the ID of the one hidden product. */
    public const PRODUCT_OPTION = 'kalaka_woocommerce_product_id';

    private function __construct()
    {
    }
}
