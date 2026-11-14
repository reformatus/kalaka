<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What a fee component returns: what each payer owes for it now, by payer-kind ID. Only `owed` in S19;
 * offers evaluated but not yet granted (the sidebar's "pending", S75/S30) are a later, additive field.
 */
final readonly class FeeTargets
{
    /** @param array<string, Money> $owed by payer-kind ID; a payer left out owes zero */
    public function __construct(public array $owed = [])
    {
    }

    public static function none(): self
    {
        return new self();
    }
}
