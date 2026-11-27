<?php

declare(strict_types=1);

namespace Kalaka\Deposits\Cli;

use Kalaka\Deposits\DepositSettings;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;

/**
 * `wp kalaka deposit <event> [<amount>] [--off]`: shows, sets or removes an event's deposit.
 *
 * The WP-CLI wrapper only: arguments, printing and the exit code. The stored amount is DepositSettings'.
 */
final class DepositCommand
{
    public function __construct(
        private readonly DepositSettings $settings = new DepositSettings(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * Shows, sets or removes the deposit of an event.
     *
     * ## OPTIONS
     *
     * <event>
     * : The event ID.
     *
     * [<amount>]
     * : The deposit in the event's currency, a number greater than zero with at most two decimals (e.g.
     * 10000). Without it and without --off the command shows the current deposit.
     *
     * [--off]
     * : Removes the event's deposit.
     *
     * ## EXAMPLES
     *
     *     wp kalaka deposit 12
     *     wp kalaka deposit 12 10000
     *     wp kalaka deposit 12 --off
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $this->run($args, $assocArgs);
        });
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     * @throws CommandFailure
     */
    private function run(array $args, array $assocArgs): void
    {
        $given = $args[0] ?? '';
        if (preg_match('/\A[1-9]\d*\z/', $given) !== 1) {
            $error = new CommandFailure('The event must be an event ID (an integer): ' . $given);
            throw $error;
        }
        $eventId = (int) $given;
        if ($this->events->find($eventId) === null) {
            $error = new CommandFailure('No event found: ' . $given);
            throw $error;
        }

        $off = isset($assocArgs['off']) && $assocArgs['off'] !== false;
        $amount = $args[1] ?? null;
        if ($off && $amount !== null) {
            $error = new CommandFailure('Give an amount or --off, not both.');
            throw $error;
        }

        if ($off) {
            $this->settings->off($eventId);
            \WP_CLI::success('Deposit removed for event ' . $eventId . '.');

            return;
        }
        if ($amount === null) {
            $stored = $this->settings->of($eventId);
            $line = $stored === null
                ? 'No deposit for event ' . $eventId . '.'
                : 'Deposit for event ' . $eventId . ': ' . $stored;
            \WP_CLI::line($line);

            return;
        }

        try {
            $this->settings->set($eventId, $amount);
        } catch (\InvalidArgumentException $e) {
            $error = new CommandFailure($e->getMessage(), $e);
            throw $error;
        }
        \WP_CLI::success('Deposit for event ' . $eventId . ': ' . $amount);
    }
}
