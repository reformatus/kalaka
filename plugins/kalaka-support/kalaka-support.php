<?php

/**
 * Plugin Name: Kaláka – Szervezeti nyilvántartás és támogatások
 * Plugin URI: https://kalaka.dev
 * Version: 0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.3
 * Author: Benedek Fodor
 * Update URI: https://kalaka.dev
 * Requires Plugins: kalaka
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

require __DIR__ . '/vendor/autoload.php';

// Core's boot runs on plugins_loaded and collects the modules registered here.
add_action(
    'kalaka_register_modules',
    static function (\Kalaka\Foundation\Api\Module\Modules $modules): void {
        $modules->add(new \Kalaka\Support\Module());
    }
);
