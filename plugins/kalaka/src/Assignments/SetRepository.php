<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;

/**
 * The only writer of `kalaka_assignment_sets`. A set is audited on its own subject (`assignment_set`, its
 * event, its ID).
 *
 * @extends EntityRepository<int, SetRecord>
 */
final class SetRepository extends EntityRepository
{
    /** @return list<SetRecord> the event's sets by ID, in one statement */
    public function ofEvent(int $eventId): array
    {
        return $this->findWhere('event_id = %d ORDER BY id', [$eventId]);
    }

    public static function subjectOf(int $eventId, int $setId): Subject
    {
        return new Subject('assignment_set', $eventId, (string) $setId);
    }

    protected function table(): string
    {
        return 'kalaka_assignment_sets';
    }

    protected function keyColumns(): array
    {
        return ['id'];
    }

    protected function autoIncrement(): bool
    {
        return true;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'event_id' => $entity->eventId,
            'version' => $entity->version,
            'name' => $entity->name,
            'time_slot' => $entity->timeSlot,
            'required' => $entity->required ? 1 : 0,
            'mode' => $entity->mode->value,
            'window_opens_at' => $entity->windowOpensAt === null ? null : Instant::toStorage($entity->windowOpensAt),
            'window_closes_at' => $entity->windowClosesAt === null ? null : Instant::toStorage($entity->windowClosesAt),
            'published' => $entity->published ? 1 : 0,
            'request_field_key' => $entity->requestFieldKey,
        ];
    }

    protected function fromRow(array $row): object
    {
        return SetRecord::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf($record->eventId, $record->id);
    }
}
