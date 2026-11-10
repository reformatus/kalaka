<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_decisions`.
 *
 * @extends AppendOnlyRepository<int, DecisionRecord>
 */
final class DecisionRepository extends AppendOnlyRepository
{
    protected function table(): string
    {
        return 'kalaka_decisions';
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
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'kind' => $entity->kind,
            'state' => $entity->state,
            'effective_at' => self::utc($entity->effectiveAt),
            'recorded_at' => self::utc($entity->recordedAt),
            'cause_type' => $entity->cause->type,
            'cause_event_id' => $entity->cause->eventId,
            'cause_key' => $entity->cause->key,
            'actor_type' => $entity->actorType,
            'actor_user_id' => $entity->actorUserId,
            'actor_event_id' => $entity->actorEventId,
            'actor_code' => $entity->actorCode,
        ];
    }

    protected function fromRow(array $row): object
    {

        return new DecisionRecord(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['kind'],
            (string) $row['state'],
            Instant::fromStorage((string) $row['effective_at']),
            Instant::fromStorage((string) $row['recorded_at']),
            new Subject(
                (string) $row['cause_type'],
                $row['cause_event_id'] === null ? null : (int) $row['cause_event_id'],
                (string) $row['cause_key'],
            ),
            (string) $row['actor_type'],
            $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            $row['actor_event_id'] === null ? null : (int) $row['actor_event_id'],
            $row['actor_code'],
            (int) $row['id'],
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

    private static function utc(\DateTimeImmutable $time): string
    {
        return Instant::toStorage($time);
    }
}
