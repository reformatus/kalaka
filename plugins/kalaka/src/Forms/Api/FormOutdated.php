<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * The page was built from a form version that is no longer the latest: only the latest revision is
 * accepted for a re-fetch or a submit. Its own code (`kalaka_form_outdated`) tells the page to have
 * the person reload. Decision N8 (#156), its text approved by the owner on 2026-11-10.
 * Not audited: nothing was attempted.
 */
final class FormOutdated extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Az űrlap időközben megváltozott. Töltsd újra az oldalt, és próbáld újra.';
    }

    public function errorCode(): string
    {
        return 'kalaka_form_outdated';
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
