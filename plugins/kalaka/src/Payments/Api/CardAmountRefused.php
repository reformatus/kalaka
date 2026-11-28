<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Money\Money;

/**
 * Starting a card payment is refused: the amount the registrant asked for is below the effective minimum or above
 * the registration's payable amount. It carries both limits (`CardAmountLimits`), so the caller can show them.
 * Its participant text is UX's: until it is approved it is `RuleViolation`'s generic text.
 * [UNAPPROVED N810]
 */
final class CardAmountRefused extends RuleViolation
{
    public function __construct(public readonly Money $min, public readonly Money $max)
    {
        parent::__construct();
    }

    public function errorCode(): string
    {
        return 'kalaka_card_amount_refused';
    }
}
