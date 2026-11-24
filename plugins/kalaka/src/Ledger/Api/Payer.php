<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

/** A payer is a payer-kind ID; the participant is the default. */
final class Payer
{
    public const PARTICIPANT = 'participant';

    private function __construct()
    {
    }

    /** The form of a payer-kind ID: lowercase, at most 50 characters. */
    public static function isValid(string $payer): bool
    {
        return preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $payer) === 1;
    }
}
