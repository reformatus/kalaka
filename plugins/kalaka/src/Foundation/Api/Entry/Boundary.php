<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Entry;

use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\Error\SiteClosed;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ErrorLog;

/**
 * The error boundary of every entry point: a REST route, a WP-CLI command method, a background hook,
 * a rendered page or block (foundation.md, "Errors"). Anything that escapes is shown like a use-case
 * error: a `KalakaException` as it is, anything else as a `SystemFailure`, logged with the request's
 * correlation ID. A `KalakaException` no run recorded is logged too when it is a 500.
 */
final class Boundary
{
    /**
     * Registers a REST route the way `register_rest_route()` does, with every endpoint's
     * `callback` and `permission_callback` behind the boundary. Routes are registered only here.
     *
     * @param non-falsy-string $namespace
     * @param non-falsy-string $route
     * @param array<int|string, mixed> $args one endpoint, or a list of them, plus an optional `schema`
     */
    public static function route(string $namespace, string $route, array $args): bool
    {
        $what = 'REST /' . $namespace . $route;
        if (isset($args[0])) {
            foreach ($args as $key => $endpoint) {
                if (is_int($key) && is_array($endpoint)) {
                    $args[$key] = self::guardedEndpoint($what, $endpoint);
                }
            }
        } else {
            $args = self::guardedEndpoint($what, $args);
        }

        return register_rest_route($namespace, $route, $args);
    }

    /**
     * Runs a WP-CLI command method's body. A `CommandFailure` prints its own message; a
     * `KalakaException` prints the operator's text (a 500 keeps its context); anything else
     * is logged and prints `SystemFailure`'s. Each ends the command with exit code 1. Inside it a
     * command stops by throwing `CommandFailure`, never by calling `WP_CLI::error()` itself.
     *
     * @param \Closure(): void $body
     */
    public static function cli(\Closure $body): void
    {
        try {
            $body();

            return;
        } catch (CommandFailure $e) {
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            if (class_exists('WP_CLI\ExitException', false) && $e instanceof \WP_CLI\ExitException) {
                throw $e;
            }
            $message = self::shown('WP-CLI command', $e)->operatorMessage();
        }

        \WP_CLI::error($message);
    }

    /**
     * Runs work nobody watches (a WP-Cron handler): nothing escapes, and what would have is logged,
     * unless a run recorded it or it is `SiteClosed`, whose load failure the boot logs once.
     *
     * @param \Closure(): mixed $body
     */
    public static function background(string $what, \Closure $body): void
    {
        try {
            $body();
        } catch (\Throwable $e) {
            if (!$e instanceof KalakaException || !$e instanceof SiteClosed && !Runner::recorded($e)) {
                ErrorLog::failure($what, $e);
            }
        }
    }

    /**
     * Logs a failure the caller handled itself and neither rethrows nor shows: one line of the error log,
     * with the request's correlation ID and the whole exception chain. For a module that keeps an error
     * on its own record and also wants it in the log (a mail delivery's error).
     * // [UNAPPROVED N6] an Api route to the error-log writer.
     */
    public static function report(string $what, \Throwable $error): void
    {
        ErrorLog::failure($what, $error);
    }

    /**
     * Renders a page or a block: its HTML, or, when anything escapes, the error in its place.
     *
     * @param \Closure(): string $body
     */
    public static function render(string $what, \Closure $body): string
    {
        try {
            return $body();
        } catch (\Throwable $e) {
            return self::html(self::shown($what, $e));
        }
    }

    /**
     * The error shown for what escaped: a `KalakaException` as it is, anything else as a
     * `SystemFailure` wrapping it. What no run recorded is logged, unless it is a refusal the
     * person can act on (below 500) or `SiteClosed`, whose load failure the boot logs once.
     */
    public static function shown(string $what, \Throwable $error): KalakaException
    {
        if ($error instanceof KalakaException) {
            if ($error->status() >= 500 && !$error instanceof SiteClosed && !Runner::recorded($error)) {
                ErrorLog::failure($what, $error);
            }

            return $error;
        }

        ErrorLog::failure($what, $error);

        return new SystemFailure(null, $error);
    }

    /**
     * The error's text in a paragraph, and for a 500 the `Hibaazonosító` line, less pronounced.
     */
    private static function html(KalakaException $error): string
    {
        $lines = explode("\n", $error->displayMessage(), 2);
        $html = '<p>' . esc_html($lines[0]) . '</p>';
        if (isset($lines[1])) {
            $html .= '<p><small>' . esc_html($lines[1]) . '</small></p>';
        }

        return $html;
    }

    /**
     * @param array<int|string, mixed> $endpoint
     * @return array<int|string, mixed>
     */
    private static function guardedEndpoint(string $what, array $endpoint): array
    {
        foreach (['callback', 'permission_callback'] as $key) {
            if (isset($endpoint[$key]) && is_callable($endpoint[$key])) {
                $endpoint[$key] = self::guardedCallback($what, $endpoint[$key]);
            }
        }

        return $endpoint;
    }

    private static function guardedCallback(string $what, callable $callback): \Closure
    {
        return static function (mixed ...$args) use ($what, $callback): mixed {
            try {
                return $callback(...$args);
            } catch (\Throwable $e) {
                return self::shown($what, $e)->toWpError();
            }
        };
    }
}
