<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

/**
 * A date a comparison of a date compares with, read from a date key of the fact source and moved by
 * whole years and days (events-and-forms.md, "Date references"): `{"key": "event.start", "years":
 * -16}` is the day sixteen years before the event's start. Pure: no clock.
 */
final readonly class DateReference
{
    public function __construct(
        public string $key,
        public int $years = 0,
        public int $days = 0,
    ) {
    }

    /**
     * The referenced day moved, as `YYYY-MM-DD`; a 29 February moved to a year without one is 1 March.
     *
     * @param string $day the key's value, `YYYY-MM-DD`
     */
    public function from(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException('A date reference moves a day given as YYYY-MM-DD.');
        }

        return $date->modify(sprintf('%+d years %+d days', $this->years, $this->days))->format('Y-m-d');
    }
}
