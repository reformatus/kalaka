<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * The pending mail of a published event is not held but on its way, so it is not cleared (mail.md, "Pausing").
 * Its own code and, as a draft for the lektor, its own text.
 */
final class EventMailNotHeld extends RuleViolation
{
    // [UNAPPROVED T517]
    public const TEXT = 'Közzétett esemény várakozó levelei nem törölhetők: ezek küldésre várnak.';

    public function __construct()
    {
        parent::__construct(self::TEXT);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_not_held';
    }
}
