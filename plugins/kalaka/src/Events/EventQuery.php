<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Query\Query;

/** Reads events across the site: the periodic run's sweep and the event list. */
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
     * Every event in any state, ascending by id, in one statement.
     *
     * @return list<Event>
     */
    public function all(): array
    {
        return $this->scope(function (): array {
            $rows = $this->select('event', 'SELECT * FROM %i ORDER BY id', [$this->table('kalaka_events')]);

            return array_map(static fn (array $row): Event => EventRecord::fromRow($row)->toEvent(), $rows);
        });
    }
}
