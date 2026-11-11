<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * A submit token the event already holds, sent with other answers than the submission that used it
 * (N6): a page whose token was already spent, or a cached page served to someone else. Refused, and the
 * refusal says nothing of the earlier submission. Not audited: nothing was attempted.
 */
final class SubmitTokenReused extends KalakaException
{
    protected function defaultMessage(): string
    {
        // N12 (#161): approved by the owner on 2026-11-10.
        return 'Ez az űrlap már nem küldhető be. Töltsd újra az oldalt, ha új jelentkezést szeretnél küldeni.';
    }

    public function errorCode(): string
    {
        return 'kalaka_token_reused';
    }

    public function status(): int
    {
        return 409;
    }

    public function isAudited(): bool
    {
        return false;
    }
}
