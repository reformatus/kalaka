<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A sending point is switched on only with a published template (mail.md, "Configuration"; owner 2026-11-27,
 * walkthrough 8): the refusal of `UpdateActionMail`, with its own code so the screen can say which rule stopped
 * it. The manual send's refusal of an unpublished body stays `BodyUnpublished`. [UNAPPROVED T1173]
 */
final class PointTemplateUnpublished extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(UpdateActionMail::POINT_TEMPLATE_UNPUBLISHED);
    }

    public function errorCode(): string
    {
        return 'kalaka_point_template_unpublished';
    }
}
