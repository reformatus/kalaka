<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** The answers of a submission that passed the form's definition. Not answered fields are absent. */
final readonly class ValidatedAnswers
{
    /**
     * @param array<string, mixed> $submitted field key → the answer as sent
     * @param array<string, string> $stored field key → the value to store
     */
    public function __construct(
        private array $submitted,
        private array $stored,
    ) {
    }

    /** @return array<string, mixed> */
    public function submitted(): array
    {
        return $this->submitted;
    }

    /** @return array<string, string> */
    public function stored(): array
    {
        return $this->stored;
    }
}
