<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * The save check of a form's keys against its event's participant fields. A form field that holds the key
 * of an existing participant field joins it, so it must be of the same type (owner ruling 2026-11-13, B3(a));
 * a retired field counts too, since its values keep their meaning. A `system` field is read-only: no form
 * may hold its key, so no answer ever shadows the decision it is read from. Nor may a form
 * hold an `extension` field's key unless the extension declared it form-writable (`extension_field`): such
 * a field is written only by its use case, like the aid award; `staff` fields stay writable from forms
 * (owner ruling 2026-11-13). A form that joins an `extension` field with options offers only options the
 * extension declared, any subset of them, retired ones included (`extension_option`): their values are the
 * declarer's, like the field's texts. [UNAPPROVED, #200 N9] First of all, no
 * two blocks of one form hold the same key (`duplicate_key`), whatever the form's status.
 *
 * A request field (`Api\RequestFieldType`, S44-1; a block that names no set is none) names one request-mode
 * set of the form's event (`unknown_set`), and a set has one request field: a field naming a set whose
 * request field is another key, two fields of the form naming one set, or a field that is already another
 * set's request field (`request_field_taken`). Run on every save and on every read of a live form's definition,
 * so a form written around it stays closed.
 */
final class SharedKeyCheck
{
    /** Two blocks of one form hold the same key: the problem code `DefinitionParser` uses too. */
    public const DUPLICATE_KEY = 'duplicate_key';
    public const TYPE_CLASH = 'type_clash';
    public const SYSTEM_FIELD = 'system_field';
    public const EXTENSION_FIELD = 'extension_field';
    public const UNKNOWN_SET = 'unknown_set';
    public const REQUEST_FIELD_TAKEN = 'request_field_taken';
    public const EXTENSION_OPTION = 'extension_option';

    /**
     * The first problem of the content's field blocks in document order, or null.
     *
     * @return array{string, string}|null the problem code and the field key
     */
    public static function problem(string $content, ?int $eventId, Contributions $contributions): ?array
    {
        $repeated = FieldBlocks::repeatedKey($content, $contributions);
        if ($repeated !== null) {
            return [self::DUPLICATE_KEY, $repeated];
        }
        if ($eventId === null) {
            return null;
        }
        $fields = FieldBlocks::of($content, $contributions);
        if ($fields === []) {
            return null;
        }
        $requests = self::requestProblem($content, $eventId, $contributions);
        if ($requests !== null) {
            return $requests;
        }
        $declared = [];
        // Retired fields included: a retired field's values keep their meaning, so its type holds too.
        foreach ((new ParticipantFields())->of($eventId, true) as $declaredField) {
            $declared[$declaredField->key] = $declaredField;
        }
        foreach ($fields as $key => $field) {
            $record = $declared[$key] ?? null;
            if ($record === null) {
                continue;
            }
            if ($record->source === ParticipantField::SOURCE_SYSTEM) {
                return [self::SYSTEM_FIELD, $key];
            }
            if (!$record->formWritable) {
                return [self::EXTENSION_FIELD, $key];
            }
            if ($record->type !== $field['type']) {
                return [self::TYPE_CLASH, $key];
            }
            if ($record->source === ParticipantField::SOURCE_EXTENSION && !self::declaredOptions($record, $field)) {
                return [self::EXTENSION_OPTION, $key];
            }
        }

        return null;
    }

    /**
     * The first problem of the content's request fields against the event's sets, or null.
     *
     * @return array{string, string}|null
     */
    private static function requestProblem(string $content, int $eventId, Contributions $contributions): ?array
    {
        $requests = FieldBlocks::requests($content, $contributions);
        if ($requests === []) {
            return null;
        }
        $sets = [];
        foreach ((new AssignmentSets())->ofEvent($eventId) as $set) {
            $sets[$set->id] = $set;
        }
        $named = [];
        foreach ($requests as ['key' => $key, 'set' => $setId]) {
            $set = $sets[$setId] ?? null;
            if ($set === null) {
                return [self::UNKNOWN_SET, $key];
            }
            if (
                ($set->requestFieldKey !== null && $set->requestFieldKey !== $key)
                || (isset($named[$set->id]) && $named[$set->id] !== $key)
            ) {
                return [self::REQUEST_FIELD_TAKEN, $key];
            }
            foreach ($sets as $other) {
                if ($other->id !== $set->id && $other->requestFieldKey === $key) {
                    return [self::REQUEST_FIELD_TAKEN, $key];
                }
            }
            $named[$set->id] = $key;
        }

        return null;
    }

    /**
     * Whether the form field offers only options the extension declared for its field (retired ones included).
     *
     * @param array{type: string, title: string, options: ?list<array{value: string, label: string}>} $field
     */
    private static function declaredOptions(ParticipantField $record, array $field): bool
    {
        $declared = array_column($record->options, 'value');
        foreach ($field['options'] ?? [] as $option) {
            if (!in_array($option['value'], $declared, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every key of the event, retired ones included: a derived key never takes one of them.
     *
     * @return array<string, true>
     */
    public static function takenKeys(?int $eventId): array
    {
        if ($eventId === null) {
            return [];
        }
        $taken = [];
        foreach ((new ParticipantFields())->of($eventId, true) as $field) {
            $taken[$field->key] = true;
        }

        return $taken;
    }
}
