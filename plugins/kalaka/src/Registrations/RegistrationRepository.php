<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The only writer of `kalaka_registrations`.
 *
 * @extends EntityRepository<RegistrationKey, Registration>
 */
final class RegistrationRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_registrations';
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
            'event_id' => $entity->key->eventId,
            'code' => $entity->key->code,
            'version' => $entity->version,
            'status' => $entity->status->value,
            'secret' => $entity->secret,
            'created_at' => self::stored($entity->createdAt),
            'registered_at' => $entity->registeredAt === null ? null : self::stored($entity->registeredAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        return new Registration(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            RegistrationStatus::from((string) $row['status']),
            (string) $row['secret'],
            self::instant((string) $row['created_at']),
            $row['registered_at'] === null ? null : self::instant($row['registered_at']),
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->key);
    }

    protected function registrationOf(object $record): RegistrationKey
    {
        return $record->key;
    }

    protected function secretColumns(): array
    {
        return ['secret'];
    }

    protected function lockLevel(): LockLevel
    {
        return LockLevel::Registration;
    }

    protected function insertsReferenceCode(): bool
    {
        return true;
    }

    /** UTC, as the `datetime(6)` column returns it. */
    private static function stored(\DateTimeImmutable $time): string
    {
        return Instant::toStorage($time);
    }

    private static function instant(string $stored): \DateTimeImmutable
    {
        return Instant::fromStorage($stored);
    }
}
