<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A code of a manual send is no registration of the event: the whole batch is refused (mail.md, "Five kinds of
 * mail", Manual). Its own code and, as a draft for the lektor, its own text.
 */
final class UnknownRegistrationCode extends RuleViolation
{
    // [UNAPPROVED T518]
    public const TEXT = 'Van olyan kód, amely nem ennek az eseménynek a jelentkezése. '
        . 'Egy levél sem került a küldési sorba.';

    public function __construct()
    {
        parent::__construct(self::TEXT);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_unknown_code';
    }
}
