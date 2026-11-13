<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The save check of a form's keys against its event's participant fields. A form field that holds the key
 * of an existing participant field joins it, so it must be of the same type (owner ruling 2026-11-13, B3(a));
 * a retired field counts too, since its values keep their meaning. A `system` field is read-only: no form
 * may hold its key, so no answer ever shadows the decision it is read from. Nor may a form
 * hold an `extension` field's key unless the extension declared it form-writable (`extension_field`): such
 * a field is written only by its use case, like the aid award; `staff` fields stay writable from forms
 * (owner ruling 2026-11-13). First of all, no
 * two blocks of one form hold the same key (`duplicate_key`), whatever the form's status.
 */
final class SharedKeyCheck
{
    /** Two blocks of one form hold the same key: the problem code `DefinitionParser` uses too. */
    public const DUPLICATE_KEY = 'duplicate_key';
    public const TYPE_CLASH = 'type_clash';
    public const SYSTEM_FIELD = 'system_field';
    public const EXTENSION_FIELD = 'extension_field';

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
        $declared = [];
        foreach ((new ParticipantFieldQuery())->forEvent($eventId) as $record) {
            $declared[$record->key] = $record;
        }
        foreach ($fields as $key => $field) {
            $record = $declared[$key] ?? null;
            if ($record === null) {
                continue;
            }
            if ($record->source === ParticipantFieldRecord::SOURCE_SYSTEM) {
                return [self::SYSTEM_FIELD, $key];
            }
            if (!$record->writableFromForms()) {
                return [self::EXTENSION_FIELD, $key];
            }
            if ($record->type !== $field['type']) {
                return [self::TYPE_CLASH, $key];
            }
        }

        return null;
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
        foreach ((new ParticipantFieldQuery())->forEvent($eventId) as $record) {
            $taken[$record->key] = true;
        }

        return $taken;
    }
}
