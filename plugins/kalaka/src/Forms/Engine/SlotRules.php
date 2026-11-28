<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormField;
use Kalaka\Forms\Api\PageDefinition;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * events-and-forms.md, "Forms and the registration slot": a form in the registration slot has
 * exactly one contact email and one display name, neither inside a condition branch; an additional
 * form has neither.
 */
final class SlotRules
{
    /** The problem code of a required participant field the registration form lacks (`SlotRequirement`). */
    public const REQUIRED_FIELD_MISSING = 'required_field_missing';

    /**
     * An extension's requirement (`Api\SlotRequirement`): each key must be a required field of the form, outside
     * any condition branch and any conditional page. Event-dependent, so it is not part of `check()`, which
     * every version of a form passes: it applies to the form as served and as saved live, like the account block.
     *
     * @param list<string> $keys
     * @return array{string, string}|null the problem code and the first missing key
     */
    public function requiredFieldProblem(FormDefinition $definition, array $keys): ?array
    {
        foreach ($keys as $key) {
            $field = $definition->field($key);
            if (
                $field === null
                || !$field->isRequired()
                || $field->branches !== []
                || ($field->page !== null && ($definition->pages[$field->page] ?? null)?->condition !== null)
            ) {
                return [self::REQUIRED_FIELD_MISSING, $key];
            }
        }

        return null;
    }

    /**
     * @param list<FormField> $fields
     * @param list<PageDefinition> $pages the form's pages: neither marking may sit on
     *     a page with a condition, which could be skipped
     * @throws SystemFailure
     */
    public function check(int $formId, FormPlacement $placement, array $fields, array $pages = []): void
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

        foreach ($fields as $field) {
            if (($field->isContactEmail() || $field->isDisplayName()) && $field->branches !== []) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('marking_in_branch ' . $field->key);
            }
            if (
                ($field->isContactEmail() || $field->isDisplayName())
                && $field->page !== null
                && ($pages[$field->page] ?? null)?->condition !== null
            ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('marking_on_conditional_page ' . $field->key);
            }
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
