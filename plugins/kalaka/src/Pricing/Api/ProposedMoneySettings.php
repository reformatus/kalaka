<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * Money settings proposed for an event, for a preview and an apply. A setting left null stays as it is.
 * S19 has the fixed price only; each money extension adds its own settings (S25 the ladder, …).
 */
final readonly class ProposedMoneySettings
{
    public function __construct(public ?Money $fixedPrice = null)
    {
    }
}
