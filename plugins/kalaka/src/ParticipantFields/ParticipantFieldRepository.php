<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_participant_fields`. A participant field is its own audit subject,
 * `participant_field` with the event and the field key.
 *
 * @extends EntityRepository<array{eventId: int, key: string}, ParticipantFieldRecord>
 */
final class ParticipantFieldRepository extends EntityRepository
{
    /**
     * The event's participant fields, retired ones included, read `FOR UPDATE` in one statement.
     *
     * @return list<ParticipantFieldRecord>
     */
    public function forEventForUpdate(int $eventId, Context $context): array
    {
        return $this->findWhere('event_id = %d', [$eventId], $context);
    }

    protected function table(): string
    {
        return ParticipantFieldRecord::TABLE;
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'field_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'field_key' => $key['key']];
    }

    protected function toRow(object $entity): array
    {
        return $entity->toRow();
    }

    protected function fromRow(array $row): object
    {
        return ParticipantFieldRecord::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return new Subject('participant_field', $record->eventId, $record->key);
    }
}
