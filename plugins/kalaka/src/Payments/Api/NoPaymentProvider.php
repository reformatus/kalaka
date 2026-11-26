<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Starting a card payment is refused: no payment provider is contributed, or it does not accept the event's
 * currency. The portal maps the code to its text. [UNAPPROVED Shapes]
 */
final class NoPaymentProvider extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_no_payment_provider';
    }
}
