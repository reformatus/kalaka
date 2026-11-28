<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A layout without `{{content}}` exactly once (mail.md, "Configuration"): a refusal of
 * `SetEventMailLayout`, with its own code and the text it always had.
 */
final class LayoutPlaceholderCount extends RuleViolation
{
    public function __construct()
    {
        parent::__construct(SetEventMailLayout::NO_PLACEHOLDER);
    }

    public function errorCode(): string
    {
        return 'kalaka_mail_layout_placeholder';
    }
}
