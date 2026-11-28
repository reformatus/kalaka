<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A code of a manual send is no registration of the event: the whole batch is refused (mail.md, "Five kinds of
 * mail", Manual). Its own code; the text is the rule violation's until one is approved.
 */
final class UnknownRegistrationCode extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_mail_unknown_code';
    }
}
