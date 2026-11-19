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
    /** @param array<string, array<string, Money>> $byComponent line source key (a component's ID, or `<ID>:<item>`) → payer → amount owed */
    public function __construct(
        public RegistrationFacts $facts,
        public Currency $currency,
        public array $byComponent,
    ) {
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
