<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AccessMode;

/** The stored assignment set (`kalaka_assignment_sets`). */
final class SetRecord
{
    public function __construct(
        public int $eventId,
        public string $name,
        public ?string $timeSlot = null,
        public bool $required = false,
        public AccessMode $mode = AccessMode::Request,
        public bool $published = false,
        public ?string $requestFieldKey = null,
        public int $id = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['event_id'],
            (string) $row['name'],
            $row['time_slot'],
            $row['required'] === '1',
            AccessMode::from((string) $row['mode']),
            $row['published'] === '1',
            $row['request_field_key'],
            (int) $row['id'],
            (int) $row['version'],
        );
    }
}
