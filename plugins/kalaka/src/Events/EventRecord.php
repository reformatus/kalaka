<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventPoint;
use Kalaka\Events\Api\EventState;

/**
 * The stored event. The core points are `YYYY-MM-DD` days, `archivedAt` is `Y-m-d H:i:s` in UTC.
 */
final class EventRecord
{
    /** @param list<array{id: int, name: string, date: string}> $points the custom points, ordered by id */
    public function __construct(
        public string $name = '',
        public EventState $state = EventState::Draft,
        public ?int $capacity = null,
        public bool $isFull = false,
        public ?string $registrationOpensOn = null,
        public ?string $registrationClosesOn = null,
        public ?string $startsOn = null,
        public ?string $endsOn = null,
        public array $points = [],
        public int $nextPointId = 1,
        public ?string $archivedAt = null,
        public int $id = 0,
        public int $version = 0,
    ) {
    }

    public function toEvent(): Event
    {
        $customPoints = [];
        foreach ($this->points as $point) {
            $customPoints[] = new EventPoint($point['id'], $point['name'], self::startOf($point['date']));
        }

        return new Event(
            $this->id,
            $this->version,
            $this->name,
            $this->state,
            $this->capacity,
            $this->isFull,
            self::startOfOrNull($this->registrationOpensOn),
            self::startOfOrNull($this->registrationClosesOn),
            self::startOfOrNull($this->startsOn),
            self::startOfOrNull($this->endsOn),
            $customPoints,
        );
    }

    /** Local 00:00 of the day in the site's time zone. */
    private static function startOf(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day . ' 00:00:00', wp_timezone());
    }

    private static function startOfOrNull(?string $day): ?\DateTimeImmutable
    {
        return $day === null ? null : self::startOf($day);
    }
}
