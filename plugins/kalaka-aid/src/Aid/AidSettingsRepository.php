<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_aid_settings`. The key is the event's ID.
 *
 * @extends EntityRepository<int, AidSettings>
 */
final class AidSettingsRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_aid_settings';
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
        return ['event_id' => $entity->eventId, 'version' => $entity->version, 'label' => $entity->label];
    }

    protected function fromRow(array $row): object
    {
        return new AidSettings((int) $row['event_id'], (string) $row['label'], (int) $row['version']);
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
