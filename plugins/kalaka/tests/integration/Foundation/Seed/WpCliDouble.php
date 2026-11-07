<?php

declare(strict_types=1);

// WP-CLI is not loaded in PHPUnit. This records the calls SeedCommand makes and, like the real
// WP_CLI::error(), does not return. It is defined only when the real class is absent.
namespace {
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

            public static function success(string $message): void
            {
                self::$calls[] = ['success', $message];
            }

            public static function error(string $message, int $exitCode = 1): void
            {
                self::$calls[] = ['error', [$message, $exitCode]];

                throw new \RuntimeException('WP_CLI::error');
            }
        }
    }
}
