<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationFacts;

/**
 * What the fee composition says a registration owes now (money.md, "Materializer"): per component and payer,
 * from the event's current settings and the registration's facts. A value only; nothing is written.
 */
final readonly class ComposedFee
{
    /**
     * @param array<string, array<string, Money>> $byComponent line source key (a component's ID, or `<ID>:<item>`)
     *                                                         → payer → amount owed
     */
    public function __construct(
        public RegistrationFacts $facts,
        public Currency $currency,
        public array $byComponent,
        /** @var array<string, FeeStage> component ID → its stage, for `totalBefore()` */
        public array $stages = [],
    ) {
    }

    /**
     * What the payer owes from the components of the stages before the given one, in composition order: the
     * "part" a later stage's component sees as its earlier-stage total. Zero when the payer owes nothing there.
     * A read of the composition; no component, line or ledger is touched.
     */
    public function totalBefore(FeeStage $stage, string $payer): Money
    {
        $total = Money::zero($this->currency);
        $order = array_flip(array_map(static fn (FeeStage $s): string => $s->value, FeeStage::cases()));
        foreach ($this->byComponent as $source => $owed) {
            $own = $this->stages[explode(':', $source)[0]] ?? null;
            if ($own === null || $order[$own->value] >= $order[$stage->value] || !isset($owed[$payer])) {
                continue;
            }
            $total = $total->plus($owed[$payer]);
        }

        return $total;
    }

    /**
     * Each payer's target: the sum of what it owes for every component, in payer order.
     *
     * @return array<string, Money> by payer-kind ID
     */
    public function targets(): array
    {
        $targets = [];
        foreach ($this->byComponent as $owed) {
            foreach ($owed as $payer => $amount) {
                $targets[$payer] = isset($targets[$payer]) ? $targets[$payer]->plus($amount) : $amount;
            }
        }
        ksort($targets, SORT_STRING);

        return $targets;
    }
}
