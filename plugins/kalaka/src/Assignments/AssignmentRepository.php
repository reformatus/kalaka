<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_assignments`. A fact is audited on its registration, each column as the item
 * `assignment:<set ID>.<column>`, and marks the registration touched. Its rows sit at the lock level after
 * the registration rows (`LockLevel::Other`): a use case locks the registration first.
 *
 * @extends EntityRepository<array{registration: RegistrationKey, setId: int}, AssignmentRecord>
 */
final class AssignmentRepository extends EntityRepository
{
    /** @return list<AssignmentRecord> the registration's assignments, in one statement */
    public function ofRegistration(RegistrationKey $registration): array
    {
        return $this->findWhere(
            'event_id = %d AND code = %s ORDER BY set_id',
            [$registration->eventId, $registration->code]
        );
    }

    /**
     * The assignments to one option of a set, in one statement.
     *
     * @return list<AssignmentRecord>
     */
    public function holding(int $eventId, int $setId, string $optionKey): array
    {
        return $this->findWhere('event_id = %d AND set_id = %d AND option_key = %s', [$eventId, $setId, $optionKey]);
    }

    protected function table(): string
    {
        return 'kalaka_assignments';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'code', 'set_id'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return [
            'event_id' => $key['registration']->eventId,
            'code' => $key['registration']->code,
            'set_id' => $key['setId'],
        ];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'set_id' => $entity->setId,
            'version' => $entity->version,
            'option_key' => $entity->optionKey,
            'source' => $entity->source->value,
            'state' => $entity->state->value,
        ];
    }

    protected function fromRow(array $row): object
    {
        return AssignmentRecord::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->registration);
    }

    protected function registrationOf(object $record): RegistrationKey
    {
        return $record->registration;
    }

    protected function auditField(string $column, object $entity): string
    {
        return 'assignment:' . $entity->setId . '.' . $column;
    }
}
