<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce\Cli;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\WooCommerce\CardSurcharge;

/**
 * `wp kalaka card-surcharge <event> [--percent=] [--fixed=] [--off]`: sets, shows or removes an event's card
 * surcharge (a fee line on the WooCommerce order, never ledger money). CLI only; there is no screen.
 */
final class CardSurchargeCommand
{
    public function __construct(private readonly CardSurcharge $surcharge = new CardSurcharge())
    {
    }

    public static function register(): void
    {
        \WP_CLI::add_command('kalaka card-surcharge', new self(), [
            'shortdesc' => 'Sets, shows or removes an event\'s card surcharge.',
        ]);
    }

    /**
     * Sets, shows or removes an event's card surcharge.
     *
     * The fee on the WooCommerce order is the amount times the percent, plus the fixed amount, rounded up to the
     * payable unit. The ledger records only the amount the registrant entered. Without options the event's setting
     * is shown. `--percent` replaces the whole setting (a missing `--fixed` removes the fixed amount).
     *
     * ## OPTIONS
     *
     * <event>
     * : The event's ID.
     *
     * [--percent=<percent>]
     * : A decimal text from 0 to 100 with up to two decimals.
     *
     * [--fixed=<amount>]
     * : A decimal text in the event's currency, added to the percentage. Needs --percent.
     *
     * [--off]
     * : Removes the event's surcharge.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        Boundary::cli(function () use ($args, $assocArgs): void {
            $eventId = ctype_digit($args[0] ?? '') ? (int) $args[0] : 0;
            if ($eventId <= 0 || (new Events())->find($eventId) === null) {
                $error = new CommandFailure('No event found: ' . ($args[0] ?? ''));
                throw $error;
            }
            $percent = $assocArgs['percent'] ?? null;
            $fixed = $assocArgs['fixed'] ?? null;
            $off = !empty($assocArgs['off']);
            if ($off && ($percent !== null || $fixed !== null)) {
                $error = new CommandFailure('--off takes no other option.');
                throw $error;
            }
            if ($off) {
                $this->surcharge->off($eventId);
                \WP_CLI::success('Event ' . $eventId . ' has no card surcharge.');

                return;
            }
            if ($percent === null) {
                if ($fixed !== null) {
                    $error = new CommandFailure('--fixed needs --percent.');
                    throw $error;
                }
                $setting = $this->surcharge->of($eventId);
                \WP_CLI::line($setting === null
                    ? 'Event ' . $eventId . ' has no card surcharge.'
                    : 'Event ' . $eventId . ': ' . $setting['percent'] . ' %'
                        . ($setting['fixed'] === null ? '' : ' + ' . $setting['fixed']));

                return;
            }
            if (!is_string($percent) || ($fixed !== null && !is_string($fixed))) {
                $error = new CommandFailure('--percent and --fixed are decimal texts.');
                throw $error;
            }
            try {
                $this->surcharge->set($eventId, $percent, $fixed);
            } catch (InvalidInput) {
                $error = new CommandFailure(
                    '--percent is a decimal text from 0 to 100 with up to two decimals;'
                    . ' --fixed a non-negative decimal text.'
                );
                throw $error;
            }
            \WP_CLI::success('Event ' . $eventId . ' has a card surcharge.');
        });
    }
}
