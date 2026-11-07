<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventPoint;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;

/**
 * The stored event. The core points are `YYYY-MM-DD` days, `archivedAt` is `Y-m-d H:i:s` in UTC.
 */
final class EventRecord
{
    /** @param list<array{id: int, name: string, date: string}> $points the custom points, ordered by id */
    public function __construct(
        public string $name = '',
        public EventState $state = EventState::Draft,
        public Currency $currency = Currency::HUF,
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

    /** @param array<string, ?string> $row a `kalaka_events` row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['name'],
            EventState::from((string) $row['state']),
            Currency::from((string) $row['currency']),
            $row['capacity'] === null ? null : (int) $row['capacity'],
            $row['is_full'] === '1',
            $row['registration_opens_on'],
            $row['registration_closes_on'],
            $row['starts_on'],
            $row['ends_on'],
            self::decodePoints((string) $row['points']),
            (int) $row['next_point_id'],
            $row['archived_at'],
            (int) $row['id'],
            (int) $row['version'],
        );
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
            $this->currency,
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

    /** @return list<array{id: int, name: string, date: string}> */
    private static function decodePoints(string $json): array
    {
        $decoded = json_decode($json, true);
        $points = [];
        foreach (is_array($decoded) ? $decoded : [] as $point) {
            if (
                !is_array($point)
                || !is_int($point['id'] ?? null)
                || !is_string($point['name'] ?? null)
                || !is_string($point['date'] ?? null)
            ) {
                $error = new SystemFailure(null, new \UnexpectedValueException('A stored custom point is malformed.'));
                throw $error;
            }
            $points[] = ['id' => $point['id'], 'name' => $point['name'], 'date' => $point['date']];
        }

        return $points;
    }
}
