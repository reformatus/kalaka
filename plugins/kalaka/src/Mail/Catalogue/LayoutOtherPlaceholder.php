<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A layout with a placeholder other than `{{content}}` (mail.md, "Configuration"): a refusal of
 * `SetEventMailLayout`, with its own code and the text it always had.
 */
final class LayoutOtherPlaceholder extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(SetEventMailLayout::OTHER_PLACEHOLDER);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_layout_other_placeholder';
    }
}
