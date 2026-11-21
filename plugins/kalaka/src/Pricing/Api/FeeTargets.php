<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What a fee component returns: what each payer owes for it now, by payer-kind ID. Offers evaluated but not
 * yet granted (the sidebar's "pending", S75/S30) are a later, additive field.
 *
 * A component that owes several things apart (one line each, e.g. an event's extra charges) returns them
 * with `ofLines()`: each line key is its own ledger line source, `<component ID>.<line key>`, and `owed` is
 * their sum per payer. A line a component stops returning is reversed, as a component's line always is.
 */
final readonly class FeeTargets
{
    /**
     * @param array<string, Money> $owed by payer-kind ID; a payer left out owes zero
     * @param array<string, array<string, Money>> $lines line key (`[a-z][a-z0-9_]*`) → payer → amount owed;
     *     when not empty, `owed` is their sum
     */
    public function __construct(public array $owed = [], public array $lines = [])
    {
    }

    /**
     * Targets of lines kept apart.
     *
     * @param array<string, array<string, Money>> $lines line key → payer → amount owed
     */
    public static function ofLines(array $lines): self
    {
        $owed = [];
        foreach ($lines as $payers) {
            foreach ($payers as $payer => $amount) {
                $owed[$payer] = isset($owed[$payer]) ? $owed[$payer]->plus($amount) : $amount;
            }
        }

        return new self($owed, $lines);
    }

    public static function none(): self
    {
        return new self();
    }
}
