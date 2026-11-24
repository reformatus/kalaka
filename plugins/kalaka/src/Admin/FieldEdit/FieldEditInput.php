<?php

declare(strict_types=1);

namespace Kalaka\Admin\FieldEdit;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\FieldNotStaffEditable;
use Kalaka\Forms\Api\FieldEditing;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The part of a staff field edit that must happen outside the run: the field is looked up, refused when staff may
 * not write it, and the value is turned into its stored form by the field type, which runs outside any transaction.
 * It reads the participant field and form definitions only, never registration data (the type's own work is
 * `Forms\Api\FieldEditing`'s). The ability and the command both call it and hand the result to `EditFieldValue`.
 *
 * Staff may edit a field of source `form` or `staff`, and an `extension` field that a form may write. They never
 * edit a `system` field, an `extension` field only its own act writes, or a retired field. No form condition is
 * walked: staff override, as for placing into a set option. [UNAPPROVED N162, N164]
 */
final class FieldEditInput
{
    public const REASON_MAX = 500;

    public function __construct(
        private readonly Forms $forms = new Forms(),
        private readonly FieldEditing $editing = new FieldEditing(),
        private readonly Access $access = new Access(),
    ) {
    }

    /**
     * @param array<string, mixed> $input the use case's input: `event_id`, `code`, `field`, `value`, `reason`
     * @throws PermissionDenied the actor may not write the field's column of the registration: decided before
     *     anything about the field is looked up, so a missing field and one the actor may not write look the same
     * @throws NotFound the event has no such field
     * @throws FieldNotStaffEditable a field staff do not edit
     * @throws InvalidInput a blank reason, or a value the field type does not take
     */
    public function prepare(array $input, Actor $actor): PreparedEdit
    {
        $key = new RegistrationKey((int) ($input['event_id'] ?? 0), (string) ($input['code'] ?? ''));
        if (!$this->access->mayWrite($actor, $key, 'field:' . (string) ($input['field'] ?? ''))) {
            $error = new PermissionDenied();
            throw $error;
        }
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason, 'UTF-8') > self::REASON_MAX) {
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

        return new PreparedEdit($eventId, $field, $this->editing->stored($field, $input['value'] ?? null));
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
}
