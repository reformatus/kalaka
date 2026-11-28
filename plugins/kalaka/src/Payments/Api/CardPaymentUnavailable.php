<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Starting a card payment is refused: the registration is not registered, its event is not published, a request
 * awaits an answer, or nothing is due. The portal maps the code to its text. [UNAPPROVED Shapes]
 */
final class CardPaymentUnavailable extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_card_payment_unavailable';
    }
}
