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
     * The referenced day moved by the years, then by the days, as `YYYY-MM-DD`. A day the moved year's
     * month does not have becomes that month's last day: 29 February moved to a year without one is
     * 28 February, not 1 March (N9). So "born after the start minus N years" is exactly "under N at the
     * start" when the start is a 29 February: someone born on the 1 March of the moved year is still
     * under N on the start, someone born on its 28 February is not.
     *
     * @param string $day the key's value, `YYYY-MM-DD`
     */
    public function from(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException('A date reference moves a day given as YYYY-MM-DD.');
        }

        $year = (int) $date->format('Y') + $this->years;
        $month = (int) $date->format('n');
        $first = $date->setDate($year, $month, 1);
        $moved = $first->setDate($year, $month, min((int) $date->format('j'), (int) $first->format('t')));

        return $moved->modify(sprintf('%+d days', $this->days))->format('Y-m-d');
    }
}
