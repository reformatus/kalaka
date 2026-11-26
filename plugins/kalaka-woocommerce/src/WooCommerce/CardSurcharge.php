<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * The per-event card surcharge: a fee line on the WooCommerce order, never ledger money. It is the option
 * `kalaka_woocommerce_card_surcharge`, a map of event ID to `{percent, fixed?}`: `percent` a decimal text from 0 to
 * 100 with up to two decimals, `fixed` an optional decimal text in the event's currency. An event that is not in
 * the map has no surcharge. It is set only by `wp kalaka card-surcharge`; there is no screen. [UNAPPROVED N812]
 *
 * The fee is `amount × percent / 100 + fixed`, rounded up to the currency's payable unit (`Money`'s own rounding,
 * which first drops what is below the minor unit). The ledger records only the amount the registrant entered.
 */
final class CardSurcharge
{
    public const OPTION = 'kalaka_woocommerce_card_surcharge';

    /**
     * The fee of a payment of this amount in an event: null when the event has no surcharge or the fee is not above
     * zero.
     */
    public function feeFor(int $eventId, Money $amount): ?Money
    {
        $setting = $this->of($eventId);
        if ($setting === null) {
            return null;
        }
        $currency = $amount->currency();
        // Exact: `Money` rounds the percentage up to its eight decimals, and the whole fee is rounded up to the payable
        // unit after the fixed amount is added.
        $fee = $amount->percent($setting['percent']);
        if ($setting['fixed'] !== null) {
            $fee = $fee->plus(Money::of($setting['fixed'], $currency));
        }
        $fee = $fee->roundedUpToPayableUnit();

        return $fee->isPositive() ? $fee : null;
    }

    /**
     * The event's setting, or null when it has none. A stored value that is not this shape is read as no surcharge
     * and reported: a malformed setting never charges anyone.
     *
     * @return ?array{percent: string, fixed: ?string}
     */
    public function of(int $eventId): ?array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored) || !isset($stored[(string) $eventId])) {
            if (!is_array($stored)) {
                $this->bad($eventId);
            }

            return null;
        }
        $entry = $stored[(string) $eventId];
        try {
            if (!is_array($entry) || !is_string($entry['percent'] ?? null)) {
                throw new InvalidInput();
            }
            $fixed = $entry['fixed'] ?? null;
            if ($fixed !== null && !is_string($fixed)) {
                throw new InvalidInput();
            }

            return self::checked($entry['percent'], $fixed);
        } catch (InvalidInput) {
            $this->bad($eventId);

            return null;
        }
    }

    /**
     * @throws InvalidInput when the percent is not a decimal text from 0 to 100 with up to two decimals, or the
     *                      fixed amount is not a non-negative decimal text
     */
    public function set(int $eventId, string $percent, ?string $fixed): void
    {
        $setting = self::checked($percent, $fixed);
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $stored[(string) $eventId] = $setting['fixed'] === null
            ? ['percent' => $setting['percent']]
            : ['percent' => $setting['percent'], 'fixed' => $setting['fixed']];
        update_option(self::OPTION, $stored, false);
    }

    public function off(int $eventId): void
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        unset($stored[(string) $eventId]);
        update_option(self::OPTION, $stored, false);
    }

    /**
     * @return array{percent: string, fixed: ?string}
     * @throws InvalidInput
     */
    private static function checked(string $percent, ?string $fixed): array
    {
        // Two decimals at most, from 0 to 100.
        if (preg_match('/\A(100(\.0{1,2})?|[0-9]{1,2}(\.[0-9]{1,2})?)\z/', $percent) !== 1) {
            throw new InvalidInput();
        }
        if ($fixed !== null) {
            if (preg_match('/\A[0-9]{1,18}(\.[0-9]{1,8})?\z/', $fixed) !== 1) {
                throw new InvalidInput();
            }
            // In range for `Money`, whatever the currency.
            Money::of($fixed, Currency::HUF);
        }

        return ['percent' => $percent, 'fixed' => $fixed];
    }

    private function bad(int $eventId): void
    {
        Boundary::report(
            'card surcharge',
            new \UnexpectedValueException(self::OPTION . ' of event ' . $eventId . ' is malformed; no surcharge.')
        );
    }
}
