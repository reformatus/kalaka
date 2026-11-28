<?php

declare(strict_types=1);

// The stand-in for the exception real WP-CLI halts with, so `Boundary::cli` rethrows it unchanged.
// It is defined only when the real class is absent.
namespace WP_CLI {
    if (!class_exists('WP_CLI\ExitException', false)) {
        // phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps -- the stand-in keeps the real class name WP_CLI\ExitException
        final class ExitException extends \RuntimeException
        {
            public function __construct(public readonly int $exit_code = 0, string $message = '')
            {
                parent::__construct($message);
            }
        }
    }
}
