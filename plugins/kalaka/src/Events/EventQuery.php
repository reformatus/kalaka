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
}
