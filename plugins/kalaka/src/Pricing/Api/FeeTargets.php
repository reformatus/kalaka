<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Forms\Api\Value;
use Kalaka\Foundation\Api\Money\Money;

/**
 * What a fee component returns: what each payer owes for it now, by payer-kind ID, and the computed values it
 * puts into the registration's facts for the components of the later stages (never stored; foundation.md,
 * "Facts, decisions and computed values"), e.g. the price ladder's `price_tier.applied`. Offers evaluated but
 * not yet granted (the sidebar's "pending", S75/S30) are a later, additive field.
 */
final readonly class FeeTargets
{
    /**
     * @param array<string, Money> $owed by payer-kind ID; a payer left out owes zero
     * @param array<string, Value> $computed dotted keys the facts do not have yet [UNAPPROVED, #200 N12]
     */
    public function __construct(public array $owed = [], public array $computed = [])
    {
    }

    public static function none(): self
    {
        return new self();
    }
}
