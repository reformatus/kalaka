<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

final class InvalidInput extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Hibás vagy hiányzó adat.';
    }

    public function errorCode(): string
    {
        return 'kalaka_invalid_input';
    }

    public function status(): int
    {
        return 400;
    }

    public function isAudited(): bool
    {
        return false;
    }
}
