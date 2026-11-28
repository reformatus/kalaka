<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * One payer's money table for one registration (the participant's unless `MoneyRead::of()` is given another payer;
 * the class keeps its name), read for every status: the owed rows with their total,
 * the movements with their net, and the balance. Rows and totals are exact; only `due` is rounded up to the
 * payable unit. `due` is set unless the payer overpaid (zero when settled); `overpaid` is set only then.
 */
final readonly class ParticipantMoney
{
    /**
     * @param list<OwedRow> $owed
     * @param list<MovementRow> $movements
     */
    public function __construct(
        public Currency $currency,
        public array $owed,
        public array $movements,
        public Money $owedTotal,
        public Money $paidTotal,
        public Money $balance,
        public ?Money $due,
        public ?Money $overpaid,
    ) {
    }
}
