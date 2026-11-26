<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AccessMode;
use Kalaka\Foundation\Api\Time\Instant;

/** The stored assignment set (`kalaka_assignment_sets`); the window's ends are UTC instants. */
final class SetRecord
{
    public function __construct(
        public int $eventId,
        public string $name,
        public ?string $timeSlot = null,
        public bool $required = false,
        public AccessMode $mode = AccessMode::Request,
        public ?\DateTimeImmutable $windowOpensAt = null,
        public ?\DateTimeImmutable $windowClosesAt = null,
        public bool $published = false,
        public ?string $requestFieldKey = null,
        public int $id = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row */
    public static function fromRow(array $row): self
    {
        $instant = static fn (?string $stored): ?\DateTimeImmutable
            => $stored === null ? null : Instant::fromStorage($stored);

        return new self(
            (int) $row['event_id'],
            (string) $row['name'],
            $row['time_slot'],
            $row['required'] === '1',
            AccessMode::from((string) $row['mode']),
            $instant($row['window_opens_at']),
            $instant($row['window_closes_at']),
            $row['published'] === '1',
            $row['request_field_key'],
            (int) $row['id'],
            (int) $row['version'],
        );
    }
}
