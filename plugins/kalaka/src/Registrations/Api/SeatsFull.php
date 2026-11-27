<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/** The pool has no seat left for an act that may not go over its limit. */
final class SeatsFull extends RuleViolation
{
    /** What a registrant is told when the choice they made filled up (a refused submit). */
    public const TEXT = 'Ez a lehetőség időközben betelt. Válassz másikat.';

    protected function defaultMessage(): string
    {
        return self::TEXT;
    }

    public function errorCode(): string
    {
        return 'kalaka_seats_full';
    }

    public function status(): int
    {
        return 409;
    }

    public function isAudited(): bool
    {
        return false;
    }
}
