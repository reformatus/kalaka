<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

/**
 * The one product every request line of this plugin points at: virtual, hidden from the catalog, never sold on
 * its own. Its name and price are set per order item. Created on first use; recreated when it is missing or
 * trashed. The ID is kept in one option.
 */
final class HiddenProduct
{
    /** @return int the product's ID */
    public function id(): int
    {
        $id = (int) get_option(OrderMeta::PRODUCT_OPTION, 0);
        $product = $id > 0 ? wc_get_product($id) : false;
        if ($product instanceof \WC_Product && $product->get_status() === 'publish') {
            return $id;
        }

        $product = new \WC_Product_Simple();
        $product->set_name('Kaláka');
        $product->set_status('publish');
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
