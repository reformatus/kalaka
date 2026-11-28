<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

/**
 * The one product every request line of this plugin points at: virtual, private (no page, no catalog), and not
 * purchasable, so only an order of this plugin can hold it. Its name and price are set per order item. Created on
 * first use; recreated when it is missing or trashed. The ID is kept in one option.
 */
final class HiddenProduct
{
    /**
     * @internal the `woocommerce_is_purchasable` filter: nobody adds this product to a cart
     * @param mixed $purchasable
     * @param mixed $product
     * @return mixed
     */
    public function purchasable($purchasable, $product)
    {
        return $product instanceof \WC_Product && $product->get_id() === (int) get_option(OrderMeta::PRODUCT_OPTION, 0)
            ? false
            : $purchasable;
    }

    /** @return int the product's ID */
    public function id(): int
    {
        $id = (int) get_option(OrderMeta::PRODUCT_OPTION, 0);
        $product = $id > 0 ? wc_get_product($id) : false;
        if ($product instanceof \WC_Product && $product->get_status() === 'private') {
            return $id;
        }

        $product = new \WC_Product_Simple();
        // [UNAPPROVED T82] the product's name, seen in the shop's product list
        $product->set_name('Kaláka-befizetés');
        $product->set_status('private');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_tax_status('none');
        $product->set_regular_price('0');
        $product->set_sold_individually(true);
        $id = $product->save();
        update_option(OrderMeta::PRODUCT_OPTION, $id, false);

        return $id;
    }
}
