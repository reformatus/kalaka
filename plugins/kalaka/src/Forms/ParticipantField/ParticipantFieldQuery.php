<?php

declare(strict_types=1);

namespace Kalaka\Forms\ParticipantField;

use Kalaka\Foundation\Api\Query\Query;

/** Reads an event's participant fields without a lock: for the editor, the save check and the key derivation. */
final class ParticipantFieldQuery extends Query
{
    /**
     * One statement on the primary key's event prefix, in the order the fields were declared.
     *
     * @return list<ParticipantFieldRecord> retired ones included
     */
    public function forEvent(int $eventId): array
    {
        return $this->scope(function () use ($eventId): array {
            $rows = $this->select(
                'participant_field',
                'SELECT * FROM %i WHERE event_id = %d ORDER BY created_at, field_key',
                [$this->table(ParticipantFieldRecord::TABLE), $eventId]
            );

            return array_map(ParticipantFieldRecord::fromRow(...), $rows);
        });
    }
}
