<?php

declare(strict_types=1);

namespace Kalaka\Support\Share;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_support_share`. The key is the event's ID; a row means the share is on.
 *
 * @extends EntityRepository<int, ShareSettings>
 */
final class ShareSettingsRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_support_share';
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
        return ['event_id' => $entity->eventId, 'version' => $entity->version];
    }

    protected function fromRow(array $row): object
    {
        return new ShareSettings((int) $row['event_id'], (int) $row['version']);
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
