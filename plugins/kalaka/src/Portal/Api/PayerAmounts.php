<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/** One payer's charged, paid and balance (charged − paid; above zero due, below zero overpaid). */
final readonly class PayerAmounts
{
    public function __construct(
        public string $payer,
        public Money $charged,
        public Money $paid,
        public Money $balance,
    ) {
    }
}
