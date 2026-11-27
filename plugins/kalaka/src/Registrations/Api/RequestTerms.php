<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * What an aid request is measured against (money.md, "Sponsor and church support"): `fullPrice`, what the
 * participant has to pay for the event before the aid, and `canPay`, what they stated they can pay (null when
 * they stated none). The aid asked is the difference, never below zero; an award above `fullPrice` is refused.
 * Both are in the event's currency. [UNAPPROVED N1131] [UNAPPROVED N1133]
 */
final readonly class RequestTerms
{
    public function __construct(public Money $fullPrice, public ?Money $canPay)
    {
    }

    /** The most an award can be: the full price. */
    public function maxAmount(): Money
    {
        return $this->fullPrice;
    }

    /** The full price less what they can pay, floored at zero; null when they stated no amount. */
    public function asked(): ?Money
    {
        if ($this->canPay === null) {
            return null;
        }
        $asked = $this->fullPrice->minus($this->canPay);

        return $asked->isPositive() ? $asked : Money::zero($this->fullPrice->currency());
    }
}
