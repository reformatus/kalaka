<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A body that is not published is assigned, switched on or sent (mail.md, "Words"): the refusal of
 * `UpdateActionMail` and of the manual send, with its own code and the text they both had.
 */
final class BodyUnpublished extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(UpdateActionMail::BODY_UNPUBLISHED);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_body_unpublished';
    }
}
