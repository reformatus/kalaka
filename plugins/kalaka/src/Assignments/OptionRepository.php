<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_assignment_options`. An option is audited on its set's subject, each column
 * as the item `option:<key>.<column>`.
 *
 * @extends EntityRepository<array{setId: int, key: string}, OptionRecord>
 */
final class OptionRepository extends EntityRepository
{
    /** @return list<OptionRecord> the options of the event's sets by set, position and key, in one statement */
    public function ofEvent(int $eventId): array
    {
        return $this->findWhere('event_id = %d ORDER BY set_id, position, option_key', [$eventId]);
    }

    protected function table(): string
    {
        return 'kalaka_assignment_options';
    }

    protected function keyColumns(): array
    {
        return ['set_id', 'option_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['set_id' => $key['setId'], 'option_key' => $key['key']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'set_id' => $entity->setId,
            'option_key' => $entity->key,
            'event_id' => $entity->eventId,
            'version' => $entity->version,
            'position' => $entity->position,
            'label' => $entity->label,
            'capacity' => $entity->capacity,
            'leaders' => $entity->leaders,
            'place' => $entity->place,
        ];
    }

    protected function fromRow(array $row): object
    {
        return OptionRecord::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return SetRepository::subjectOf($record->eventId, $record->setId);
    }

    protected function auditField(string $column, object $entity): string
    {
        return 'option:' . $entity->key . '.' . $column;
    }
}
