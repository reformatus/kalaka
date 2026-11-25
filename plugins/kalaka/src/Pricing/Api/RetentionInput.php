<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationFacts;

/**
 * Everything a retention rule gets (modules-and-extensions.md, "Contracts"): the registration's facts, what each
 * payer paid net (read from payment facts only, payers in key order, zero or less included), and the fee
 * composition before retention (component ID → payer → amount). [UNAPPROVED N671]
 */
final readonly class RetentionInput
{
    /**
     * @param array<string, Money> $paid payer → net payments
     * @param array<string, array<string, Money>> $composed
     */
    public function __construct(
        public RegistrationFacts $facts,
        public Currency $currency,
        public array $paid,
        public array $composed,
    ) {
    }
}
