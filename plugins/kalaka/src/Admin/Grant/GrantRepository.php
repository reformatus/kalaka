<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_grants`.
 *
 * @extends EntityRepository<int, Grant>
 */
final class GrantRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_grants';
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
            'event_id' => $entity->eventId,
            'version' => $entity->version,
            'user_id' => $entity->userId,
            'view_id' => $entity->viewId,
            'bound_value' => $entity->boundValue,
            'actions' => $entity->actionsJson(),
        ];
    }

    protected function fromRow(array $row): object
    {
        return Grant::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return new Subject('grant', $record->eventId, (string) $record->id);
    }
}
