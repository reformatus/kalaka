<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * Staff do not edit this field: a `system` field, a field only its own act writes (an answer, an award) or a
 * retired field. Its own code; the text is the rule violation's.
 */
final class FieldNotStaffEditable extends RuleViolation
{
    public function errorCode(): string
    {
        return 'kalaka_field_not_staff_editable';
    }
}
