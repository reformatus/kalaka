<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

/**
 * An assignment set of an event (modules-and-extensions.md, "Assignment sets"): a name, an optional time
 * slot, required or optional, an access mode and a published switch, with its options in
 * their order. `requestFieldKey` is the set's one request field (S44-1): the participant field whose values
 * are the requests, tied when a form first holds a request field naming the set, and never changed after.
 * `required` only marks the tally "missing a required choice"; whether a form insists is the request
 * field's own `required`.
 */
final readonly class AssignmentSet
{
    /** @param list<AssignmentOption> $options ordered by position, then key */
    public function __construct(
        public int $id,
        public int $eventId,
        public int $version,
        public string $name,
        public ?string $timeSlot,
        public bool $required,
        public AccessMode $mode,
        public bool $published,
        public ?string $requestFieldKey,
        public array $options,
    ) {
    }

    public function option(string $key): ?AssignmentOption
    {
        foreach ($this->options as $option) {
            if ($option->key === $key) {
                return $option;
            }
        }

        return null;
    }
}
