<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Starting a card payment is refused: the request has no valid WordPress nonce (`kalaka_card_payment`), so it is
 * not this page's own form or its token expired. [UNAPPROVED N832] The route checks it before anything else, so a
 * cached page or a forged cross-site post starts no payment and makes no order. No text of its own: the rule
 * violation's generic text. Alternative: reuse `PermissionDenied`, or check the nonce only for a form post.
 */
final class CardNonceInvalid extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_card_nonce_invalid';
    }
}
