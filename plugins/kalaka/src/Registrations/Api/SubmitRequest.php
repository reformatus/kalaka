<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * What the form page sends to submit a registration, with what the entry point knows of the person:
 * the roles of the current user and whether the form's password was given.
 */
final readonly class SubmitRequest
{
    /**
     * @param int $version the form version the page was built from; only the latest is accepted
     * @param array<array-key, mixed> $answers field key → the answer as sent
     * @param string $token the page's `SubmitToken`
     * @param list<string> $roles the current user's roles; none for a guest
     */
    public function __construct(
        public int $formId,
        public int $version,
        public array $answers,
        public string $token,
        public array $roles = [],
        public bool $passwordGiven = false,
    ) {
    }
}
