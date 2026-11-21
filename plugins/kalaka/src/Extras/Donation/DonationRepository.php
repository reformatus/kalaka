<?php

declare(strict_types=1);

namespace Kalaka\Extras\Donation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_extras_donations`.
 *
 * @extends EntityRepository<int, DonationRecord>
 */
final class DonationRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_extras_donations';
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
        return ['event_id' => $key];
    }

    protected function toRow(object $entity): array
    {
        return ['event_id' => $entity->eventId, 'field_key' => $entity->fieldKey, 'version' => $entity->version];
    }

    protected function fromRow(array $row): object
    {
        return new DonationRecord((int) $row['event_id'], (string) $row['field_key'], (int) $row['version']);
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
