<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Forms\Api\Value;
use Kalaka\Foundation\Api\Money\Money;

/**
 * What a fee component returns: what each payer owes for it now, by payer-kind ID, as one line per payer
 * (`owed`) or one line per item and payer (`items`, `ofItems()`; the manual charges and discounts: one item
 * each), and the computed values it puts into the registration's facts for the components of the later stages
 * (never stored; foundation.md, "Facts, decisions and computed values"), e.g. the price ladder's
 * `price_tier.applied`. Offers evaluated but not yet granted (the sidebar's "pending", S75/S30) are a later,
 * additive field.
 */
final readonly class FeeTargets
{
    /**
     * @param array<string, Money> $owed by payer-kind ID; a payer left out owes zero
     * @param array<array-key, array<string, Money>> $items item key → payer-kind ID → amount, when the component
     *     owes per item; then `owed` is their sum per payer and the materializer writes one line per item
     * @param array<string, Value> $computed dotted keys the facts do not have yet [UNAPPROVED, #200 N12]
     */
    public function __construct(public array $owed = [], public array $items = [], public array $computed = [])
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * A component that owes per item: `owed` is the sum of the items per payer, for the stage totals.
     *
     * @param array<array-key, array<string, Money>> $items item key (`[a-z0-9_]+`) → payer-kind ID → amount
     */
    public static function ofItems(array $items): self
    {
        $owed = [];
        foreach ($items as $payers) {
            foreach ($payers as $payer => $amount) {
                $owed[$payer] = isset($owed[$payer]) ? $owed[$payer]->plus($amount) : $amount;
            }
        }

        return new self($owed, $items);
    }
}
