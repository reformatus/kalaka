<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Registrations\RegistrationList;

/** The sorszám of an event's registrations, computed by `RegistrationList` and never stored. */
final class Serials
{
    public function __construct(private readonly RegistrationList $list = new RegistrationList())
    {
    }

    /**
     * One statement on the event's rows.
     *
     * @return array<string, int> the sorszám by code; a registration without one is left out
     */
    public function forEvent(int $eventId): array
    {
        $serials = [];
        foreach ($this->list->forEvent($eventId) as $row) {
            if ($row['serial'] !== null) {
                $serials[$row['code']] = $row['serial'];
            }
        }

        return $serials;
    }
}
