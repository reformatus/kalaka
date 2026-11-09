<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventPoint;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Time\Instant;

/**
 * The stored event. Every point and `archivedAt` is a UTC instant; the custom points are JSON in `points`.
 */
final class EventRecord
{
    /** @param list<array{id: int, name: string, at: \DateTimeImmutable}> $points the custom points, ordered by id */
    public function __construct(
        public string $name = '',
        public EventState $state = EventState::Draft,
        public Currency $currency = Currency::HUF,
        public ?int $capacity = null,
        public bool $isFull = false,
        public ?\DateTimeImmutable $registrationOpensAt = null,
        public ?\DateTimeImmutable $registrationClosesAt = null,
        public ?\DateTimeImmutable $startsAt = null,
        public ?\DateTimeImmutable $endsAt = null,
        public array $points = [],
        public int $nextPointId = 1,
        public ?\DateTimeImmutable $archivedAt = null,
        public int $id = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row a `kalaka_events` row */
    public static function fromRow(array $row): self
    {
        $instant = static fn (?string $stored): ?\DateTimeImmutable
            => $stored === null ? null : Instant::fromStorage($stored);

        return new self(
            (string) $row['name'],
            EventState::from((string) $row['state']),
            Currency::from((string) $row['currency']),
            $row['capacity'] === null ? null : (int) $row['capacity'],
            $row['is_full'] === '1',
            $instant($row['registration_opens_at']),
            $instant($row['registration_closes_at']),
            $instant($row['starts_at']),
            $instant($row['ends_at']),
            self::decodePoints((string) $row['points']),
            (int) $row['next_point_id'],
            $instant($row['archived_at']),
            (int) $row['id'],
            (int) $row['version'],
        );
    }

    public function toEvent(): Event
    {
        $customPoints = [];
        foreach ($this->points as $point) {
            $customPoints[] = new EventPoint($point['id'], $point['name'], $point['at']);
        }

        return new Event(
            $this->id,
            $this->version,
            $this->name,
            $this->state,
            $this->currency,
            $this->capacity,
            $this->isFull,
            $this->registrationOpensAt,
            $this->registrationClosesAt,
            $this->startsAt,
            $this->endsAt,
            $customPoints,
        );
    }

    /** @return list<array{id: int, name: string, at: \DateTimeImmutable}> */
    private static function decodePoints(string $json): array
    {
        $decoded = json_decode($json, true);
        $points = [];
        foreach (is_array($decoded) ? $decoded : [] as $point) {
            if (
                !is_array($point)
                || !is_int($point['id'] ?? null)
                || !is_string($point['name'] ?? null)
                || !is_string($point['at'] ?? null)
            ) {
                $error = new SystemFailure(null, new \UnexpectedValueException('A stored custom point is malformed.'));
                throw $error;
            }
            $points[] = ['id' => $point['id'], 'name' => $point['name'], 'at' => Instant::fromStorage($point['at'])];
        }

        return $points;
    }
}
