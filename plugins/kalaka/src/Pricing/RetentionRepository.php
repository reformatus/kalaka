<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_retention`.
 *
 * @extends EntityRepository<int, RetentionRecord>
 */
final class RetentionRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_retention';
    }

    protected function keyColumns(): array
    {
        return ['event_id'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return ['event_id' => $entity->eventId, 'version' => $entity->version, 'rule' => $entity->rule];
    }

    protected function fromRow(array $row): object
    {
        return new RetentionRecord(
            (int) $row['event_id'],
            (string) $row['rule'],
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
