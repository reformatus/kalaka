<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Registrations\Api\RegistrationTables;

/** The seats held in the event's own pool: its live registrations (read by `Seats::held()`). */
final class RegistrationCount extends Query
{
    /** The live registrations of the event: one range read inside the event's primary key. */
    public function registered(int $eventId): int
    {
        return $this->scope(fn (): int => (int) ($this->select(
            'count',
            'SELECT COUNT(*) AS registered FROM %i WHERE event_id = %d AND ' . RegistrationTables::isLive(),
            [$this->table('kalaka_registrations'), $eventId]
        )[0]['registered'] ?? 0));
    }
}
