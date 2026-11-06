<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Decision;

use Kalaka\Registrations\Api\RegistrationKey;

/** Everything a rule may read: the registration, the run's moment and the decisions visible to it. */
final readonly class DecisionInput
{
    /** @param array<string, Decision> $decisions the current decision of each visible kind that has one, by kind */
    public function __construct(
        public RegistrationKey $registration,
        public \DateTimeImmutable $now,
        public array $decisions,
    ) {
    }
}
