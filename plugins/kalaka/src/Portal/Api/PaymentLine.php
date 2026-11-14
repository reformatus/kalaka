<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/** One itemised line of the participant payer (SB-13): its label, +1 a charge or −1 a reduction, the absolute amount. */
final readonly class PaymentLine
{
    public function __construct(
        public string $label,
        public int $sign,
        public Money $amount,
    ) {
    }
}
