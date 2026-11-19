<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_unconfirmations`. A write marks the registration touched, so the evaluation
 * unconfirms it in the same run. The reason is free text: it reaches the audit entry as the use case's
 * reason, and the diff masks it (`secretColumns()`).
 *
 * @extends EntityRepository<RegistrationKey, UnconfirmationRecord>
 */
final class UnconfirmationRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_unconfirmations';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'code'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key->eventId, 'code' => $key->code];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'version' => $entity->version,
            'unconfirmed_at' => Instant::toStorage($entity->unconfirmedAt),
            'unconfirmed_by' => $entity->unconfirmedBy,
            'reason' => $entity->reason,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new UnconfirmationRecord(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            Instant::fromStorage((string) $row['unconfirmed_at']),
            $row['unconfirmed_by'] === null ? null : (int) $row['unconfirmed_by'],
            (string) $row['reason'],
            (int) $row['version'],
        );
    }

    protected function secretColumns(): array
    {
        return ['reason'];
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->registration);
    }

    protected function registrationOf(object $record): RegistrationKey
    {
        return $record->registration;
    }
}
