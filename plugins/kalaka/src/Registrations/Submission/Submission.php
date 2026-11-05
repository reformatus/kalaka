<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Submission;

use Kalaka\Registrations\Api\RegistrationKey;

/** One submission of a registration: 1 for the one that created it, then 2, 3, … for supplementary ones. */
final readonly class Submission
{
    /** @param array<string, mixed> $answers field key → answer as submitted */
    public function __construct(
        public RegistrationKey $registration,
        public int $number,
        public int $formId,
        public int $formVersion,
        public array $answers,
        public \DateTimeImmutable $submittedAt,
    ) {
    }
}
