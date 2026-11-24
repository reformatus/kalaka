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

    /**
     * Whether a line is the participant's own money moving, a payment or a refund: a payment fact of the
     * participant payer that is not a correction. The one rule of which lines a "payment recorded" mail is
     * for and of which line its keys read.
     */
    public static function isParticipantMovement(LineKind $kind, string $payer, bool $correction): bool
    {
        return $kind->isPaymentFact() && !$correction && $payer === self::PARTICIPANT;
    }

    /** The form of a payer-kind ID: lowercase, at most 50 characters. */
    public static function isValid(string $payer): bool
    {
        return preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $payer) === 1;
    }
}
