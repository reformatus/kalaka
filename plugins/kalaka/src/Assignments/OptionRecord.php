<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AssignmentOption;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** The stored option of a set (`kalaka_assignment_options`); `eligibility` is the condition's JSON. */
final class OptionRecord
{
    /** @param array<array-key, mixed>|null $condition */
    public function __construct(
        public readonly int $setId,
        public readonly string $key,
        public readonly int $eventId,
        public string $label,
        public ?int $capacity = null,
        public string $leaders = '',
        public string $place = '',
        public ?array $condition = null,
        public int $position = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row */
    public static function fromRow(array $row): self
    {
        $condition = null;
        if ($row['eligibility'] !== null) {
            $condition = json_decode($row['eligibility'], true);
            if (!is_array($condition)) {
                $error = new SystemFailure(
                    null,
                    new \UnexpectedValueException('A stored eligibility condition is malformed.')
                );
                throw $error;
            }
        }

        return new self(
            (int) $row['set_id'],
            (string) $row['option_key'],
            (int) $row['event_id'],
            (string) $row['label'],
            $row['capacity'] === null ? null : (int) $row['capacity'],
            (string) $row['leaders'],
            (string) $row['place'],
            $condition,
            (int) $row['position'],
            (int) $row['version'],
        );
    }

    public function toOption(): AssignmentOption
    {
        return new AssignmentOption(
            $this->setId,
            $this->key,
            $this->label,
            $this->capacity,
            $this->leaders,
            $this->place,
            $this->condition,
            $this->position,
        );
    }
}
