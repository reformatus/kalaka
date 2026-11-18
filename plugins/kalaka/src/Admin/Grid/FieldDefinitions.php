<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The event's participant fields as the grid reads them (S37), from `Forms::participantFields()` and the
 * event's form definitions. It reads WordPress posts, so it runs outside any use case's transaction (the
 * REST routes and the page), once per request: the definitions are kept per form.
 *
 * Required for the sum row: a field is required when it sits in at least one form of the event and is
 * required (`FormField::isRequired()`) in every form it sits in; a field in no form, or optional in any
 * form, is optional. A form whose definition cannot be read (a broken form, which the broken-form warning
 * names) is left out of the check.
 */
final class FieldDefinitions
{
    /** The type key of a scale field (built on another PR): its sum is the average. Kept here only. */
    public const SCALE_TYPE = 'kalaka/scale-field';

    /** A consent (`Forms::CONSENT_FIELD`): stored as `1` when given; the grid counts the yes answers. */
    public const CONSENT_TYPE = Forms::CONSENT_FIELD;

    /** @var array<int, ?FormDefinition> by form ID */
    private array $definitions = [];

    public function __construct(private readonly Forms $forms = new Forms())
    {
    }

    /**
     * Every participant field of the event, retired ones included, by key.
     *
     * @return array<string, FieldDefinition>
     */
    public function of(int $eventId): array
    {
        $types = Contributions::current()->fieldTypes();
        $fields = [];
        foreach ($this->forms->participantFields($eventId, true) as $field) {
            $options = [];
            foreach ($field->options as $option) {
                $options[$option['value']] = self::plain($option['label']);
            }
            $type = $types[$field->type] ?? null;
            $fields[$field->key] = new FieldDefinition(
                $field->key,
                $field->type,
                self::plain($field->title),
                $options,
                $this->required($field->key, array_column($field->forms, 'form_id')),
                $type instanceof FieldType ? $type->valueType()->value : null,
            );
        }

        return $fields;
    }

    /** @param list<int> $formIds */
    private function required(string $key, array $formIds): bool
    {
        $seen = false;
        foreach ($formIds as $formId) {
            $definition = $this->definition($formId);
            if ($definition === null) {
                continue;
            }
            $field = $definition->field($key);
            if ($field === null) {
                continue;
            }
            if (!$field->isRequired()) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }

    private function definition(int $formId): ?FormDefinition
    {
        if (!array_key_exists($formId, $this->definitions)) {
            $form = $this->forms->form($formId);
            try {
                $this->definitions[$formId] = $form === null ? null : $this->forms->definition($form);
            } catch (SystemFailure) {
                // A broken form is the broken-form warning's to name; it does not decide the sum row.
                $this->definitions[$formId] = null;
            }
        }

        return $this->definitions[$formId];
    }

    /** A title or label may hold inline HTML; the grid shows plain text. */
    private static function plain(string $text): string
    {
        return html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
