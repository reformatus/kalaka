<?php

/**
 * Plugin Name: Kaláka
 * Plugin URI: https://kalaka.dev
 * Description: Integrált jelentkezési rendszer eseményekhez, táborokhoz
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author: Benedek Fodor
 * Update URI: https://kalaka.dev
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const KALAKA_VERSION = '0.1.0';
const KALAKA_FILE = __FILE__;

require __DIR__ . '/vendor/autoload.php';

$kalakaBoot = static function (): void {
    (new \Kalaka\Foundation\Boot([
        new \Kalaka\Foundation\Module(),
        new \Kalaka\Events\Module(),
        new \Kalaka\Forms\Module(),
        new \Kalaka\Assignments\Module(),
        new \Kalaka\Registrations\Module(),
        new \Kalaka\Pricing\Module(),
        new \Kalaka\Ledger\Module(),
        new \Kalaka\Payments\Module(),
        new \Kalaka\Mail\Module(),
        new \Kalaka\Portal\Module(),
        new \Kalaka\Admin\Module(),
    ]))->run();
};

add_action('plugins_loaded', $kalakaBoot, 10);
register_activation_hook(__FILE__, $kalakaBoot);
