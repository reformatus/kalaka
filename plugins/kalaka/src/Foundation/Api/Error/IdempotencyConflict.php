<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

/**
 * An act's retry key that already holds a different input: the caller reused a key for another act.
 * A refusal of the person's act (409). It shows the generic refusal text (`RuleViolation`'s), so no
 * new text. [UNAPPROVED N1141]
 */
final class IdempotencyConflict extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'A művelet nem hajtható végre.';
    }

    public function errorCode(): string
    {
        return 'kalaka_idempotency_conflict';
    }

    public function status(): int
    {
        return 409;
    }
}
