<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Query\Query;

/** The places an event's registrations take, for the capacity check of a submit. */
final class RegistrationCount extends Query
{
    /** The `registered` registrations of the event: one range read inside the event's primary key. */
    public function registered(int $eventId): int
    {
        return $this->scope(fn (): int => (int) ($this->select(
            'count',
            "SELECT COUNT(*) AS registered FROM %i WHERE event_id = %d AND status = 'registered'",
            [$this->table('kalaka_registrations'), $eventId]
        )[0]['registered'] ?? 0));
    }
}
