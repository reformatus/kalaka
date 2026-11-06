<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

use Kalaka\Registrations\Api\RegistrationKey;

/** The "evaluation failed" flag of a registration: the rule that failed and its error. */
final class EvaluationFailure
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public string $rule,
        public string $error,
        public \DateTimeImmutable $failedAt,
        public int $version = 0,
    ) {
    }
}
