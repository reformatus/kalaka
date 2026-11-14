<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Foundation\Api\Actor\Actor;

/** What `SubmitGuard` sees of a submit: the form, the version and answers as sent, the token and the actor. */
final readonly class SubmitAttempt
{
    /** @param array<array-key, mixed> $answers field key → the answer as sent */
    public function __construct(
        public int $formId,
        public int $version,
        public array $answers,
        public string $token,
        public Actor $actor,
    ) {
    }
}
