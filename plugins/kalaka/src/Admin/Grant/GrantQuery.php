<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Foundation\Api\Query\Query;

/**
 * Reads grants for the access checks and the view and grant use cases. It reads only Admin's own tables
 * and the WordPress users table, and never registration data. Per-event reads use `event_user` or
 * `event_view`; the reads by user alone (`user_event`) are site-wide by nature: a user's grants across
 * events.
 */
final class GrantQuery extends Query
{
    /**
     * The user's grants on the event in one statement on `event_user`, each with its id, its view's name
     * and the stored filter and columns of its view (all three null for the master view, and for a view
     * that is missing).
     *
     * @return list<array{
     *     id: int, view_id: ?int, bound_value: ?string, actions: list<string>, name: ?string,
     *     filter: ?string, columns: ?string
     * }>
     */
    public function forUser(int $eventId, int $userId): array
    {
        return $this->scope(function () use ($eventId, $userId): array {
            $rows = $this->select(
                'grant',
                'SELECT g.id, g.view_id, g.bound_value, g.actions, v.name AS view_name, v.filter AS view_filter,'
                . ' v.columns AS view_columns'
                . ' FROM %i g LEFT JOIN %i v ON v.event_id = g.event_id AND v.id = g.view_id'
                . ' WHERE g.event_id = %d AND g.user_id = %d ORDER BY g.id',
                [$this->table('kalaka_grants'), $this->table('kalaka_views'), $eventId, $userId]
            );

            $grants = [];
            foreach ($rows as $row) {
                $grants[] = [
                    'id' => (int) $row['id'],
                    'view_id' => isset($row['view_id']) ? (int) $row['view_id'] : null,
                    'bound_value' => $row['bound_value'],
                    'actions' => Grant::actionsFromStored((string) $row['actions']),
                    'name' => $row['view_name'],
                    'filter' => $row['view_filter'],
                    'columns' => $row['view_columns'],
                ];
            }

            return $grants;
        });
    }

    /** Whether any grant binds a value to the view's parameter: one statement on `event_view`. */
    public function boundToView(int $eventId, int $viewId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'grant',
            'SELECT 1 AS found FROM %i WHERE event_id = %d AND view_id = %d AND bound_value IS NOT NULL LIMIT 1',
            [$this->table('kalaka_grants'), $eventId, $viewId]
        ) !== []);
    }

    /** Whether any grant uses the view: one statement on `event_view`. */
    public function usesView(int $eventId, int $viewId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'grant',
            'SELECT 1 AS found FROM %i WHERE event_id = %d AND view_id = %d LIMIT 1',
            [$this->table('kalaka_grants'), $eventId, $viewId]
        ) !== []);
    }

    /**
     * Every grant of the event, by id: one statement on the event's rows.
     *
     * @return list<array{user_id: int, view_id: ?int, bound_value: ?string, actions: list<string>}>
     */
    public function ofEvent(int $eventId): array
    {
        return $this->scope(function () use ($eventId): array {
            $rows = $this->select(
                'grant',
                'SELECT user_id, view_id, bound_value, actions FROM %i WHERE event_id = %d ORDER BY id',
                [$this->table('kalaka_grants'), $eventId]
            );

            return array_map(static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'view_id' => isset($row['view_id']) ? (int) $row['view_id'] : null,
                'bound_value' => $row['bound_value'],
                'actions' => Grant::actionsFromStored((string) $row['actions']),
            ], $rows);
        });
    }

    /** Whether the user holds a grant on any event: one statement on `user_event`, site-wide. */
    public function anyForUser(int $userId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'grant',
            'SELECT 1 AS found FROM %i WHERE user_id = %d LIMIT 1',
            [$this->table('kalaka_grants'), $userId]
        ) !== []);
    }

    /**
     * The events where the user holds at least one grant, ascending: one statement on `user_event`,
     * site-wide.
     *
     * @return list<int>
     */
    public function eventIdsOfUser(int $userId): array
    {
        return $this->scope(fn (): array => array_map(
            static fn (array $row): int => (int) $row['event_id'],
            $this->select(
                'grant',
                'SELECT DISTINCT event_id FROM %i WHERE user_id = %d ORDER BY event_id',
                [$this->table('kalaka_grants'), $userId]
            )
        ));
    }

    /** A plain read of the WordPress users table: it fires no hook and fills no cache inside a run. */
    public function userExists(int $userId): bool
    {
        return $this->scope(fn (): bool => $this->select(
            'user',
            'SELECT ID FROM %i WHERE ID = %d',
            [$this->usersTable('users'), $userId]
        ) !== []);
    }
}
