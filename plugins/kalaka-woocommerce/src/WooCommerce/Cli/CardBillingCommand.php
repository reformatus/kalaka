<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce\Cli;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\WooCommerce\DeclareBillingFields;

/**
 * `wp kalaka card-billing <event>`: declares an event's five billing participant fields. CLI only; there is
 * no screen. (The owner's ruling 2026-11-26.)
 */
final class CardBillingCommand
{
    public static function register(): void
    {
        \WP_CLI::add_command('kalaka card-billing', new self(), [
            'shortdesc' => 'Declares an event\'s billing participant fields.',
        ]);
    }

    /**
     * Declares the event's billing participant fields: `billing_name`, `billing_country`, `billing_postcode`,
     * `billing_city` and `billing_address`, source `extension` and form-writable.
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args): void {
            $eventId = ctype_digit($args[0] ?? '') ? (int) $args[0] : 0;
            if ($eventId <= 0 || (new Events())->find($eventId) === null) {
                $error = new CommandFailure('No event found: ' . ($args[0] ?? ''));
                throw $error;
            }
            $current = get_current_user_id();
            Runner::current()->run(
                new DeclareBillingFields(),
                ['event_id' => $eventId],
                new CliActor($current > 0 ? $current : null)
            );
            \WP_CLI::success('Event ' . $eventId . ' has its billing participant fields.');
        });
    }
}
