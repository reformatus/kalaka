<?php

declare(strict_types=1);

namespace Kalaka\Admin\FieldEdit;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\FieldNotStaffEditable;
use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\OptionsFieldType;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The part of a staff field edit that must happen outside the run: the field is looked up, refused when staff may
 * not write it, and the value is turned into its stored form by the field type, which runs outside any transaction.
 * It reads the participant field and form definitions only, never registration data. The ability and the command
 * both call it and hand the result to `EditFieldValue`.
 *
 * Staff may edit a field of source `form` or `staff`, and an `extension` field that a form may write. They never
 * edit a `system` field, an `extension` field only its own act writes, or a retired field. No form condition is
 * walked: staff override, as for placing into a set option.
 */
final class FieldEditInput
{
    public const REASON_MAX = 500;

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly Access $access = new Access(),
    ) {
    }

    /**
     * @param array<string, mixed> $input the use case's input: `event_id`, `code`, `field`, `value`, `reason`
     * @throws PermissionDenied the actor may not write the field's column of the registration: decided before
     *     anything about the field is looked up, so a missing field and one the actor may not write look the same
     * @throws NotFound the event has no such field
     * @throws FieldNotStaffEditable a field staff do not edit
     * @throws InvalidInput a reason over 500 characters, or a value the field type does not take
     */
    public function prepare(array $input, Actor $actor): PreparedEdit
    {
        $key = new RegistrationKey((int) ($input['event_id'] ?? 0), (string) ($input['code'] ?? ''));
        if (!$this->access->mayWrite($actor, $key, 'field:' . (string) ($input['field'] ?? ''))) {
            $error = new PermissionDenied();
            throw $error;
        }
        $reason = $input['reason'] ?? null;
        if ($reason !== null && (!is_string($reason) || mb_strlen($reason, 'UTF-8') > self::REASON_MAX)) {
            $error = new InvalidInput();
            throw $error;
        }
        $eventId = (int) ($input['event_id'] ?? 0);
        $key = (string) ($input['field'] ?? '');
        $field = null;
        foreach ($this->forms->participantFields($eventId, true) as $candidate) {
            if ($candidate->key === $key) {
                $field = $candidate;
                break;
            }
        }
        if ($field === null) {
            $error = new NotFound();
            throw $error;
        }
        if (!self::editable($field)) {
            $error = new FieldNotStaffEditable();
            throw $error;
        }
        if (is_string($input['value'] ?? null) && trim($input['value']) === '') {
            // A blank text is not a clear: a caller that means to clear sends null.
            $error = new InvalidInput();
            throw $error;
        }

        return new PreparedEdit($eventId, $field, $this->stored($field, $input['value'] ?? null));
    }

    /** Whether staff may edit the field at all. */
    public static function editable(ParticipantField $field): bool
    {
        if ($field->retired) {
            return false;
        }

        return match ($field->source) {
            ParticipantField::SOURCE_FORM, ParticipantField::SOURCE_STAFF => true,
            ParticipantField::SOURCE_EXTENSION => $field->formWritable,
            default => false,
        };
    }

    /**
     * The stored form of the value; null for a clear (a blank value, or an answer the type calls not answered).
     */
    private function stored(ParticipantField $field, mixed $value): ?string
    {
        if (self::blank($value)) {
            return null;
        }
        $type = Contributions::current()->fieldTypes()[$field->type] ?? null;
        if ($type === null) {
            $error = new SystemFailure(
                null,
                new \LogicException('The field type ' . $field->type . ' is not installed.')
            );
            throw $error;
        }
        $attributes = $this->attributes($field, $type);
        // The shapes are tried in order: an answer the type refuses (it throws or returns an error) moves on to the
        // next, and only when none is taken is the input refused.
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

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === [];
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
            $form = $this->forms->form($held['form_id']);
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
