<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\Assignment;
use Kalaka\Assignments\Api\AssignmentSource;
use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Registrations\Api\RegistrationKey;

/** The stored assignment fact (`kalaka_assignments`): one per registration and set. */
final class AssignmentRecord
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly int $setId,
        public string $optionKey,
        public AssignmentSource $source,
        public AssignmentState $state,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (int) $row['set_id'],
            (string) $row['option_key'],
            AssignmentSource::from((string) $row['source']),
            AssignmentState::from((string) $row['state']),
            (int) $row['version'],
        );
    }

    public function toAssignment(): Assignment
    {
        return new Assignment($this->registration, $this->setId, $this->optionKey, $this->source, $this->state);
    }
}
