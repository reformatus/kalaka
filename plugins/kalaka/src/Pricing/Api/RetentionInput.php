<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationFacts;

/**
 * Everything a retention rule gets (modules-and-extensions.md, "Contracts"): the registration's facts, what each
 * payer paid net (read from payment facts only, payers in key order, zero or less included), and the fee it
 * would owe if it were still `registered`, from its own registration time (component ID → payer → amount). The
 * fee is null when a fee component failed while composing it ("cannot say"): the leaving does not fail for that,
 * since core's rules do not need the fee; a rule that does decides what to do with null (or throws, which
 * flags `money/retention`). [UNAPPROVED N671, N674]
 */
final readonly class RetentionInput
{
    /**
     * @param array<string, Money> $paid payer → net payments
     * @param array<string, array<string, Money>>|null $fee null when it cannot be said
     */
    public function __construct(
        public RegistrationFacts $facts,
        public Currency $currency,
        public array $paid,
        public ?array $fee,
    ) {
    }
}
