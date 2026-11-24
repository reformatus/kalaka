<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Seats;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatAct;

/**
 * The only writer of `kalaka_seats`. A seat is audited on its registration, each column as the item
 * `seat:<pool>.<column>`, and marks the registration touched. Its rows sit at the lock level after the
 * registration rows (`LockLevel::Other`): a use case locks the registration first.
 *
 * @extends EntityRepository<array{registration: RegistrationKey, pool: string}, SeatRecord>
 */
final class SeatRepository extends EntityRepository
{
    /** @return list<SeatRecord> the registration's seats, in one statement */
    public function ofRegistration(RegistrationKey $registration, ?Context $forUpdate = null): array
    {
        return $this->findWhere(
            'event_id = %d AND code = %s ORDER BY pool',
            [$registration->eventId, $registration->code],
            $forUpdate
        );
    }

    protected function table(): string
    {
        return 'kalaka_seats';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'code', 'pool'];
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
            'pool' => $key['pool'],
        ];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'pool' => $entity->pool,
            'version' => $entity->version,
            'taken_at' => Instant::toStorage($entity->takenAt),
            'act' => $entity->act->value,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new SeatRecord(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['pool'],
            Instant::fromStorage((string) $row['taken_at']),
            SeatAct::from((string) $row['act']),
            (int) $row['version'],
        );
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
        return 'seat:' . $entity->pool . '.' . $column;
    }
}
