<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_manual_confirmations`. A write marks the registration touched, so the evaluation
 * decides its confirmation in the same run. The reason is free text: it reaches the audit entry as the use
 * case's reason, and the diff masks it (`secretColumns()`).
 *
 * @extends EntityRepository<RegistrationKey, ManualConfirmationRecord>
 */
final class ManualConfirmationRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_manual_confirmations';
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
            'set_at' => Instant::toStorage($entity->setAt),
            'set_by' => $entity->setBy,
            'reason' => $entity->reason,
            'revoked_at' => $entity->revokedAt === null ? null : Instant::toStorage($entity->revokedAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        return new ManualConfirmationRecord(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            Instant::fromStorage((string) $row['set_at']),
            $row['set_by'] === null ? null : (int) $row['set_by'],
            (string) $row['reason'],
            $row['revoked_at'] === null ? null : Instant::fromStorage((string) $row['revoked_at']),
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
