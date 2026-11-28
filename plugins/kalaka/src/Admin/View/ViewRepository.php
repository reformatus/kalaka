<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_views`, the event's master row included (S37): `master_event` is the event of a
 * master row and null on every other row, written with `master`.
 *
 * @extends EntityRepository<int, View>
 */
final class ViewRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_views';
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
            'name' => $entity->name,
            'filter' => $entity->shape->filterJson(),
            'columns' => $entity->shape->columnsJson(),
            'counts' => $entity->shape->countsJson(),
            'master' => $entity->master ? 1 : 0,
            'master_event' => $entity->master ? $entity->eventId : null,
        ];
    }

    protected function fromRow(array $row): object
    {
        return View::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return new Subject('view', $record->eventId, (string) $record->id);
    }
}
