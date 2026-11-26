<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * An assignment fact (modules-and-extensions.md, "Assignment sets"): the one option a registration holds in
 * a set, with who wrote it and whether it is confirmed. At most one per registration and set.
 */
final readonly class Assignment
{
    public function __construct(
        public RegistrationKey $registration,
        public int $setId,
        public string $optionKey,
        public AssignmentSource $source,
        public AssignmentState $state,
    ) {
    }
}
