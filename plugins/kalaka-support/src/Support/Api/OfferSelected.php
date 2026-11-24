<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A settings save that would remove an offer, give it a limit it had not, or flip its "must be requested" flag
 * while an open registration has selected it (`OfferChangeGuard`): refused, nothing saved. Its own code, so a
 * caller that maps errors by code (the money settings CLI) names this reason and not another rule violation's;
 * the text is the rule violation's until one is approved.
 */
final class OfferSelected extends RuleViolation
{
    public const CODE = 'kalaka_offer_selected';

    public function errorCode(): string
    {
        return self::CODE;
    }
}
