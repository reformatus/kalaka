<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * events-and-forms.md, "Forms and the registration slot": a form in the registration slot has
 * exactly one contact email and one display name; an additional form has neither.
 */
final class SlotRules
{
    /**
     * @param list<FormField> $fields
     * @throws SystemFailure
     */
    public function check(int $formId, FormPlacement $placement, array $fields): void
    {
        $contactEmails = count(array_filter($fields, static fn (FormField $field): bool => $field->isContactEmail()));
        $displayNames = count(array_filter($fields, static fn (FormField $field): bool => $field->isDisplayName()));

        if ($placement === FormPlacement::Additional) {
            if ($contactEmails + $displayNames > 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('marking_on_additional_form form ' . $formId);
            }

            return;
        }

        if ($contactEmails !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('contact_email_count form ' . $formId);
        }
        if ($displayNames !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('display_name_count form ' . $formId);
        }
    }
}
