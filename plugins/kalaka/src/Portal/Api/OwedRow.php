<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/** One line of what the participant owes: its label, what it is and its signed amount (a discount is negative). */
final readonly class OwedRow
{
    public function __construct(public string $label, public OwedKind $kind, public Money $amount)
    {
    }
}
