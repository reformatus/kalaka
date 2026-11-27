<?php

declare(strict_types=1);

// WP-CLI is not loaded in PHPUnit. This records the calls the commands make and, like the real
// WP_CLI::error() and WP_CLI::confirm(), does not return. It is defined only when the real class is absent.
namespace {
    require_once __DIR__ . '/WpCliExitException.php';

    if (!class_exists('WP_CLI', false)) {
        // phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- the recording double keeps the real class name WP_CLI
        final class WP_CLI
        {
            public const IS_TEST_DOUBLE = true;

            /** @var list<array{string, mixed}> */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$calls = [];
                self::$decline = false;
            }

            /** @param array<string, mixed> $args */
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
            public static function add_command(string $name, mixed $callable, array $args = []): void
            {
                self::$calls[] = ['add_command', $name];
            }

            public static function line(string $message = ''): void
            {
                self::$calls[] = ['line', $message];
            }

            /** @var bool whether confirm() answers no, as an operator declining does */
            public static bool $decline = false;

            /** @param array<string, mixed> $assoc */
            public static function confirm(string $question, array $assoc = []): void
            {
                self::$calls[] = ['confirm', $question];
                if (!isset($assoc['yes']) && self::$decline) {
                    throw new \RuntimeException('WP_CLI::confirm declined');
                }
            }

            public static function success(string $message): void
            {
                self::$calls[] = ['success', $message];
            }

            public static function warning(string $message, bool $group = false): void
            {
                self::$calls[] = ['warning', $message];
            }

            /** @param array<string, mixed> $assocArgs */
            public static function confirm(string $question, array $assocArgs = []): void
            {
                self::$calls[] = ['confirm', $question];
                if (empty($assocArgs['yes'])) {
                    throw new \WP_CLI\ExitException(0, 'WP_CLI::confirm');
                }
            }

            public static function error(string $message, int $exitCode = 1): void
            {
                self::$calls[] = ['error', [$message, $exitCode]];

                throw new \RuntimeException('WP_CLI::error');
            }
        }
    }
}
