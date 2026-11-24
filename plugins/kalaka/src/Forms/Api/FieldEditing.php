<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Engine\FieldType\ChoiceFieldType;
use Kalaka\Forms\Engine\FieldType\ConsentFieldType;
use Kalaka\Forms\Engine\FieldType\DateFieldType;
use Kalaka\Forms\Engine\FieldType\EmailFieldType;
use Kalaka\Forms\Engine\FieldType\NameFieldType;
use Kalaka\Forms\Engine\FieldType\NumberFieldType;
use Kalaka\Forms\Engine\FieldType\PhoneFieldType;
use Kalaka\Forms\Engine\FieldType\ScaleFieldType;
use Kalaka\Forms\Engine\FieldType\TextFieldType;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\ParticipantFields\Api\ParticipantField;

/**
 * Field types for a hand edit (events-and-forms.md, "Changing participant fields after submit"): the one place
 * outside a form that turns a typed value into a field's stored form (`stored()`), and gives what a control needs
 * to open on a stored value (`editor()`). A screen or a use case never runs a field type itself. Like
 * `Forms::participantFields()` it reads the event's form posts, so it runs outside a use case's transaction.
 */
final class FieldEditing
{
    /** @var array<int, list<ParticipantField>> the event's fields with their forms, by event */
    private array $held = [];

    public function __construct(private readonly Forms $forms = new Forms())
    {
    }

    /**
     * The stored form of the value; null for a clear (no value, an empty list, or an answer the type calls not
     * answered). The attributes are those of the form field that holds the key; an options type reads the field's
     * active options, whatever the form's block lists. The shapes of a string the command line or a screen's plain
     * text may stand for are tried in turn.
     *
     * @throws InvalidInput when the type takes none of the shapes
     * @throws SystemFailure when the field's type is not installed
     */
    public function stored(ParticipantField $field, mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }
        $type = $this->type($field);
        if ($type === null) {
            $error = new SystemFailure(
                null,
                new \LogicException('The field type ' . $field->type . ' is not installed.')
            );
            throw $error;
        }
        $attributes = $this->attributes($field, $type);
        // An answer the type refuses (it throws or returns an error) moves on to the next shape; only when none is
        // taken is the input refused.
        foreach (self::shapes($value) as $shape) {
            try {
                $answer = $type->answer(new FieldTypeInput($shape, $attributes));
            } catch (InvalidInput) {
                continue;
            }
            if ($answer->error === null) {
                return $answer->value;
            }
        }
        $error = new InvalidInput();
        throw $error;
    }

    /**
     * The editor of the stored value (null: none stored); null when the field's type is not installed. The field
     * may come without the forms that hold it (`ParticipantFields::of()`): they are looked up for the event, once.
     */
    public function editor(int $eventId, ParticipantField $field, ?string $stored): ?FieldEditor
    {
        $type = $this->type($field);
        if ($type === null) {
            return null;
        }
        // Only a choice needs its form (whether it takes several); other types read their settings from the field.
        if ($field->forms === [] && $type instanceof ChoiceFieldType) {
            $this->held[$eventId] ??= $this->forms->participantFields($eventId, true);
            foreach ($this->held[$eventId] as $held) {
                if ($held->key === $field->key) {
                    $field = $held;
                    break;
                }
            }
        }
        $attributes = $this->attributes($field, $type);
        $options = null;
        if ($type instanceof OptionsFieldType) {
            $options = [];
            foreach ($field->options as $option) {
                if (!$option['retired']) {
                    $options[] = ['value' => $option['value'], 'label' => $option['label']];
                }
            }
        }

        $multiple = $type instanceof ChoiceFieldType && ($attributes['multiple'] ?? false) === true;

        return new FieldEditor(
            $type->blockName(),
            $stored === null ? null : $type->prefill(new FieldTypeInput($stored, $attributes)),
            $options,
            $multiple,
            self::control($type, $multiple)
        );
    }

    /** The simple control of the core types; null for any other (a ranking, a picker, an extension's own). */
    private static function control(FieldType $type, bool $multiple): ?string
    {
        return match (true) {
            $type instanceof ChoiceFieldType => $multiple ? FieldEditor::MULTI_CHOICE : FieldEditor::CHOICE,
            $type instanceof ConsentFieldType => FieldEditor::CONSENT,
            $type instanceof NumberFieldType, $type instanceof ScaleFieldType => FieldEditor::NUMBER,
            $type instanceof TextFieldType, $type instanceof EmailFieldType, $type instanceof NameFieldType,
                $type instanceof PhoneFieldType, $type instanceof DateFieldType => FieldEditor::TEXT,
            default => null,
        };
    }

    private function type(ParticipantField $field): ?FieldType
    {
        return Contributions::current()->fieldTypes()[$field->type] ?? null;
    }

    /**
     * The value as given, and, for a string the command line or a screen's plain text may stand for, the JSON
     * shapes a field type takes: a consent is a boolean and a scale an integer; a list may be sent as JSON.
     *
     * @return list<mixed>
     */
    private static function shapes(mixed $value): array
    {
        $shapes = [$value];
        if (is_int($value)) {
            $shapes[] = (string) $value;
        }
        if (!is_string($value)) {
            return $shapes;
        }
        $text = trim($value);
        if ($text === '1' || $text === '0') {
            $shapes[] = $text === '1';
        }
        if (preg_match('/\A-?(0|[1-9][0-9]{0,8})\z/', $text) === 1) {
            $shapes[] = (int) $text;
        }
        if (str_starts_with($text, '[')) {
            $list = json_decode($text, true);
            if (is_array($list) && array_is_list($list)) {
                $shapes[] = $list;
            }
        }

        return $shapes;
    }

    /**
     * The block attributes the type reads the answer with: the attributes of the form field holding the key (the
     * registration form's newest published version, else the first additional form that holds it, by form ID);
     * the participant field's label when no form holds it. An options type reads the field's active options,
     * whatever the form's block lists.
     *
     * @return array<string, mixed>
     */
    private function attributes(ParticipantField $field, FieldType $type): array
    {
        $attributes = $this->formAttributes($field) ?? ['label' => $field->title];
        if ($type instanceof OptionsFieldType) {
            $attributes['options'] = [];
            foreach ($field->options as $option) {
                if (!$option['retired']) {
                    $attributes['options'][] = ['value' => $option['value'], 'label' => $option['label']];
                }
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed>|null */
    private function formAttributes(ParticipantField $field): ?array
    {
        $holders = [];
        foreach ($field->forms as $held) {
            try {
                $form = $this->forms->form($held['form_id']);
            } catch (SystemFailure) {
                continue;
            }
            if ($form !== null) {
                $holders[] = $form;
            }
        }
        usort(
            $holders,
            static fn (Form $a, Form $b): int => [$a->placement !== FormPlacement::Registration, $a->id]
                <=> [$b->placement !== FormPlacement::Registration, $b->id]
        );
        foreach ($holders as $form) {
            try {
                $definition = $this->forms->definition($form);
            } catch (SystemFailure) {
                continue;
            }
            foreach ($definition->fields as $formField) {
                if ($formField->key === $field->key) {
                    return $formField->attributes;
                }
            }
        }

        return null;
    }
}
