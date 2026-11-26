<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A settings save that would remove an offer or flip its "must be requested" flag
 * while an open registration has selected it (`OfferChangeGuard`): refused, nothing saved. Its own code, so a
 * caller that maps errors by code (the money settings CLI) names this reason and not another rule violation's;
 * the text is the rule violation's until one is approved. It is not core's `ItemChosen` (final since #229:
 * one refusal, one code, for a removal): a flag flip is not a removal, and the money settings CLI names this code.
 */
final class OfferSelected extends RuleViolation
{
    public const CODE = 'kalaka_offer_selected';

    public function errorCode(): string
    {
        return self::CODE;
    }
}
