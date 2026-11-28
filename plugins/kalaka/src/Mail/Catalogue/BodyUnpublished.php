<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A body that is not published is sent (mail.md, "Words"): the manual send's refusal, with its own code and
 * the text the settings page had. The sending point's own switch-on refusal is `PointTemplateUnpublished`
 * (owner 2026-11-27, walkthrough 8).
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
