<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_evaluation_failures`.
 *
 * @extends EntityRepository<RegistrationKey, EvaluationFailure>
 */
final class EvaluationFailureRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_evaluation_failures';
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
            'rule' => $entity->rule,
            'error' => $entity->error,
            'failed_at' => Instant::toStorage($entity->failedAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        return new EvaluationFailure(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['rule'],
            (string) $row['error'],
            Instant::fromStorage((string) $row['failed_at']),
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->registration);
    }
}
