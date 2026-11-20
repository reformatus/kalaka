<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Query\Query;

/** Reads events across the site, for the periodic run's sweep. */
final class EventQuery extends Query
{
    /**
     * One statement on the `state` index.
     *
     * @return list<Event> the events whose stored state is `published`, ascending by id
     */
    public function published(): array
    {
        return $this->scope(function (): array {
            $rows = $this->select(
                'event',
                'SELECT * FROM %i WHERE state = %s ORDER BY id',
                [$this->table('kalaka_events'), EventState::Published->value]
            );

            return array_map(static fn (array $row): Event => EventRecord::fromRow($row)->toEvent(), $rows);
        });
    }

    /**
     * The events whose stored state is not `archived`, by start (events without one last), then by id, in
     * one statement.
     *
     * @return list<Event>
     */
    public function notArchived(): array
    {
        return $this->scope(function (): array {
            $rows = $this->select(
                'event',
                'SELECT * FROM %i WHERE state <> %s ORDER BY starts_at IS NULL, starts_at, id',
                [$this->table('kalaka_events'), EventState::Archived->value]
            );

            return array_map(static fn (array $row): Event => EventRecord::fromRow($row)->toEvent(), $rows);
        });
    }
}
