<?php

declare(strict_types=1);

namespace Kalaka\Ledger\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A manual entry whose idempotency key already holds a different line: the caller reused a key for other
 * input. A refusal of the person's act (409), not a system failure. It shows the generic refusal text
 * (`RuleViolation`'s), so no new text. [UNAPPROVED Shapes]
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
