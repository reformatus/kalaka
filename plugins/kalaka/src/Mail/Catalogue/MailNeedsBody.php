<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An action mail would be on without a body (mail.md, "Configuration"): a refusal of
 * `UpdateActionMail`, with its own code and the text it always had.
 */
final class MailNeedsBody extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(UpdateActionMail::NO_BODY);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_no_body';
    }
}
