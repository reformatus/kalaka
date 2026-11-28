<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * A decision kind's declared base state (the owner's ruling 2026-11-19 on confirmation): the kind is defined
 * only for the listed statuses; there a registration with no record of the kind reads `$state`, effective when
 * it became `registered`, and in any other status it reads no state, whatever its records say. It is derived,
 * never written: every read of current decisions applies it, in PHP and in SQL (`DecisionTables::stateOf()`).
 */
final readonly class DecisionDefault
{
    /** @param non-empty-list<RegistrationStatus> $statuses */
    public function __construct(
        public string $state,
        public array $statuses,
    ) {
    }

    public function holdsFor(RegistrationStatus $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    /** @return non-empty-list<string> the statuses' stored values */
    public function statusValues(): array
    {
        return array_map(static fn (RegistrationStatus $status): string => $status->value, $this->statuses);
    }
}
