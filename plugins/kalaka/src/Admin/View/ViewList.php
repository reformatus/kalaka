<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Query\Query;

/** The stored views of an event, by id: one statement on `event_id`. */
final class ViewList extends Query
{
    /** @return list<array{id: int, name: string, filter: string, columns: string}> */
    public function ofEvent(int $eventId): array
    {
        return $this->scope(fn (): array => array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'filter' => (string) $row['filter'],
                'columns' => (string) $row['columns'],
            ],
            $this->select(
                'view',
                'SELECT id, name, filter, columns FROM %i WHERE event_id = %d ORDER BY id',
                [$this->table('kalaka_views'), $eventId]
            )
        ));
    }
}
