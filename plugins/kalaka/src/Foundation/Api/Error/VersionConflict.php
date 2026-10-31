<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

final class VersionConflict extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Valaki időközben módosította ezeket az adatokat. Töltsd újra az oldalt, és próbáld újra.';
    }

    public function errorCode(): string
    {
        return 'kalaka_version_conflict';
    }

    public function status(): int
    {
        return 409;
    }
}
