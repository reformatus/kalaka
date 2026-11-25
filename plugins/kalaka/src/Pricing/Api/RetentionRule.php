<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * A retention rule (money.md, "Withdrawal, removal and substitution"; modules-and-extensions.md, "The extension
 * surface"): an inside point that says what an event keeps of the payments of a registration that withdrew or
 * was removed. It gets every payer's net payments together with the composition and returns the kept amount per
 * payer; the kept amount is held to the payer's own payments, and nothing is ever refunded. Core offers
 * `everything_paid` and `nothing`; the deposit extension adds "keep up to the deposit" later, which needs the
 * payers together. The stored value of an event's rule is the ID. It reads its own current settings and writes
 * nothing. [UNAPPROVED N671]
 */
interface RetentionRule
{
    /** `[a-z][a-z0-9_]*`, at most 20 characters, unique; the value stored for an event. */
    public function id(): string;

    /**
     * @return array<string, Money> payer → the amount kept; a payer left out keeps nothing
     * @throws \Throwable anything, which fails the composition of the registration
     */
    public function kept(RetentionInput $input): array;
}
