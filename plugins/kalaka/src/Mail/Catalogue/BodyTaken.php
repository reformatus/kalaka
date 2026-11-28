<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A body is assigned that another action mail of the event uses (mail.md, "Words": one to one): a
 * refusal of `UpdateActionMail`, with its own code and the text it always had.
 */
final class BodyTaken extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(UpdateActionMail::BODY_TAKEN);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_body_taken';
    }
}
