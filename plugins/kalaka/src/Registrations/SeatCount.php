<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Query\Query;

/** The seats held in a pool, for the seat rule's reads (`Api\Seats`). */
final class SeatCount extends Query
{
    /** The seats of the pool: one range read inside the event's index. */
    public function held(int $eventId, string $pool): int
    {
        return $this->scope(fn (): int => (int) ($this->select(
            'count',
            'SELECT COUNT(*) AS held FROM %i WHERE event_id = %d AND pool = %s',
            [$this->table('kalaka_seats'), $eventId, $pool]
        )[0]['held'] ?? 0));
    }
}
