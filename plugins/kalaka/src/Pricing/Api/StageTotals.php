<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * The totals per payer of the stages composed so far: what a component of a later stage reads, e.g. the
 * share's "base after support" = `of(Tier, participant) + of(Support, participant)` (support is negative).
 * A component never sees its own stage or a later one.
 */
final readonly class StageTotals
{
    /** @param array<string, array<string, Money>> $byStage stage value → payer → total */
    public function __construct(private Currency $currency, private array $byStage = [])
    {
    }

    /** Zero in the event's currency when the stage has no amount for the payer. */
    public function of(FeeStage $stage, string $payer): Money
    {
        return $this->byStage[$stage->value][$payer] ?? Money::zero($this->currency);
    }

    /** The payer's total over every stage composed so far. */
    public function total(string $payer): Money
    {
        $total = Money::zero($this->currency);
        foreach ($this->byStage as $payers) {
            if (isset($payers[$payer])) {
                $total = $total->plus($payers[$payer]);
            }
        }

        return $total;
    }

    /**
     * These totals with one stage's amounts added.
     *
     * @param array<string, Money> $owed payer → amount
     */
    public function with(FeeStage $stage, array $owed): self
    {
        $byStage = $this->byStage;
        foreach ($owed as $payer => $amount) {
            $byStage[$stage->value][$payer] = isset($byStage[$stage->value][$payer])
                ? $byStage[$stage->value][$payer]->plus($amount)
                : $amount;
        }

        return new self($this->currency, $byStage);
    }
}
