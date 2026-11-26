<?php

declare(strict_types=1);

namespace Kalaka\Mail\Pause;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * An hourly budget outside 1 to 2000 (mail.md, "Sending"): a refusal of `SetMailBudget`, with its own code.
 */
final class MailBudgetInvalid extends RuleViolation
{
    /** [UNAPPROVED T902] */
    public const MESSAGE = 'Az óránként legfeljebb küldhető levelek száma csak 1 és 2000 közötti egész szám lehet.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_budget_invalid';
    }
}
