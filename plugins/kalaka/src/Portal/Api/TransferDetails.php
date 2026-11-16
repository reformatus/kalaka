<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * The bank-transfer details (SB-1, SB-2, SB-6): the event's beneficiary and account number as entered, the
 * participant's balance rounded up to the payable unit, and the reference, the event's prefix and the
 * reference code.
 */
final readonly class TransferDetails
{
    public function __construct(
        public string $beneficiary,
        public string $accountNumber,
        public Money $amount,
        public string $reference,
    ) {
    }
}
