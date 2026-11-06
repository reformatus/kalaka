<?php

declare(strict_types=1);

const KALAKA_VERSION = '0.1.0';
const KALAKA_FILE = __DIR__ . '/plugins/kalaka/kalaka.php';

// The few WP-CLI members the plugin calls; WP-CLI itself is not installed for the static analysis.
if (!class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @param array<string, mixed> $args */
        public static function add_command(string $name, string|object $callable, array $args = []): bool
        {
            return true;
        }

        public static function line(string $message = ''): void
        {
        }

        public static function success(string $message): void
        {
        }

        public static function warning(string $message): void
        {
        }

        public static function error(string $message): never
        {
            exit(1);
        }
    }
}
