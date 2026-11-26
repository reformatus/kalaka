<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// The hidden product's ID and the card surcharge settings are the only options. The product, the orders and their
// meta belong to WooCommerce's records of money and stay.
delete_option('kalaka_woocommerce_product_id');
delete_option('kalaka_woocommerce_card_surcharge');
