<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_views`.
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
