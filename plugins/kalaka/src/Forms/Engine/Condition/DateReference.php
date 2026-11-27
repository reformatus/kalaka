<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;

/**
 * A date a comparison of a date compares with, read from a date key of the fact source and moved by
 * whole years and days (events-and-forms.md, "Conditions", the condition format):
 * `{"key": "event.start", "years": -16}` is the day sixteen years before the event's start. The reference
 * `{"ref": "now", "years": 0, "days": 0}` reads the fact source's `Facts::NOW`, the evaluation's moment. A reference
 * to a datetime key (`now`, the registration's opening and closing) moves its wall time. Pure: no clock.
 */
final readonly class DateReference
{
    /** The largest move either way, in years: a person's age with room to spare. */
    public const MAX_YEARS = 200;

    /** The largest move either way, in days: ten years. */
    public const MAX_DAYS = 3660;

    public function __construct(
        public string $key,
        public int $years = 0,
        public int $days = 0,
    ) {
    }

    /** A reference to "now": the fact source's `Facts::NOW` moved. */
    public static function now(int $years = 0, int $days = 0): self
    {
        return new self(Facts::NOW, $years, $days);
    }

    public function isNow(): bool
    {
        return $this->key === Facts::NOW;
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

        return $this->moved($date)->format('Y-m-d');
    }

    /**
     * A datetime value moved the same way, its wall time kept: in the value's zone (the site's) when it has one, so
     * "now + 1 day" across a daylight-saving change is the same wall time the next day; else at its own offset.
     * Returns `YYYY-MM-DDTHH:MM:SS` and the offset at the moved time.
     */
    public function fromInstant(Value $value): string
    {
        $instant = is_string($value->data) ? Value::instant($value->data) : null;
        if ($instant === null) {
            throw new \InvalidArgumentException('A date reference moves a datetime given with its offset.');
        }

        if ($this->years === 0 && $this->days === 0) {
            // No move: the instant as it is (a wall time repeated by a clock change must not be re-read).
            return $instant->format('Y-m-d\TH:i:sP');
        }

        return $this->moved($value->zone === null ? $instant : $instant->setTimezone($value->zone))
            ->format('Y-m-d\TH:i:sP');
    }

    private function moved(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $year = (int) $date->format('Y') + $this->years;
        $month = (int) $date->format('n');
        $first = $date->setDate($year, $month, 1);
        $moved = $first->setDate($year, $month, min((int) $date->format('j'), (int) $first->format('t')));

        return $moved->modify(sprintf('%+d days', $this->days));
    }
}
