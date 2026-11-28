<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Starting a card payment is refused: the registration's latest payment request is in flight at the provider
 * (authorised and not yet paid), or another start for it is running. Another request must not be made until it
 * settles. The portal maps the code to its text. [UNAPPROVED Shapes]
 */
final class CardPaymentInFlight extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_card_payment_in_flight';
    }
}
