<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * One line of a payment request: one registration, one payer and one amount, up to that payer's balance
 * (money.md, "Payments"). The label is the text the payer sees for the line.
 */
final readonly class RequestLine
{
    /** @throws \InvalidArgumentException when the payer is no payer-kind ID, the amount is not above zero or the label is empty */
    public function __construct(
        public RegistrationKey $registration,
        public string $payer,
        public Money $amount,
        public string $label,
    ) {
        if (!Payer::isValid($payer) || !$amount->isPositive() || trim($label) === '') {
            throw new \InvalidArgumentException('A request line has a payer, a positive amount and a label.');
        }
    }
}
