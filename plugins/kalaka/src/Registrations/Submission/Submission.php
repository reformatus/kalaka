<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Submission;

use Kalaka\Registrations\Api\RegistrationKey;

/** One submission of a registration: 1 for the one that created it, then 2, 3, … for additional-form submissions. */
final readonly class Submission
{
    /**
     * @param array<string, mixed> $answers field key → answer as submitted
     * @param ?string $token the form page's submit token (`Api\SubmitToken`); a secret, never logged
     * @param ?string $answersHash the fingerprint of the stored answers a repeat with the token must match
     */
    public function __construct(
        public RegistrationKey $registration,
        public int $number,
        public int $formId,
        public int $formVersion,
        public array $answers,
        public \DateTimeImmutable $submittedAt,
        public ?string $token = null,
        public ?string $answersHash = null,
    ) {
    }
}
