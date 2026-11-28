<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Starting a card payment is refused: an earlier card payment of the registration is still open at the provider
 * (authorised and not yet paid, paid and not yet recorded, or in a state the provider does not handle), or another
 * start for it is running. Another request must not be made until it settles; nothing was cancelled or created.
 * The portal maps the code to its text. [UNAPPROVED Shapes]
 */
final class CardPaymentInFlight extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_card_payment_in_flight';
    }
}
