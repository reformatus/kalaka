<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Foundation\Api\Query\Query;

/**
 * The stored views of an event, by id: one statement on `event_id`. The event's master row (S37) is never
 * one of them; `master()` reads it.
 */
final class ViewList extends Query
{
    /** @return list<array{id: int, version: int, name: string, filter: string, columns: string, counts: ?string}> */
    public function ofEvent(int $eventId): array
    {
        return $this->scope(fn (): array => array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'version' => (int) $row['version'],
                'name' => (string) $row['name'],
                'filter' => (string) $row['filter'],
                'columns' => (string) $row['columns'],
                'counts' => $row['counts'],
            ],
            $this->select(
                'view',
                'SELECT id, version, name, filter, columns, counts FROM %i'
                . ' WHERE event_id = %d AND master = 0 ORDER BY id',
                [$this->table('kalaka_views'), $eventId]
            )
        ));
    }

    /**
     * The event's master row (S37), or null when none is stored: one statement on `master_event`.
     *
     * @return ?array{id: int, version: int, counts: ?string}
     */
    public function master(int $eventId): ?array
    {
        return $this->scope(function () use ($eventId): ?array {
            $rows = $this->select(
                'view',
                'SELECT id, version, counts FROM %i WHERE master_event = %d',
                [$this->table('kalaka_views'), $eventId]
            );
            if ($rows === []) {
                return null;
            }

            return [
                'id' => (int) $rows[0]['id'],
                'version' => (int) $rows[0]['version'],
                'counts' => $rows[0]['counts'],
            ];
        });
    }

    /**
     * The counted members the event's master view stores, by column; none without a master row.
     *
     * @return array<string, list<string>>
     */
    public function masterCounts(int $eventId): array
    {
        $master = $this->master($eventId);

        return $master === null ? [] : ViewShape::fromStoredMaster($master['counts'])->counts;
    }
}
