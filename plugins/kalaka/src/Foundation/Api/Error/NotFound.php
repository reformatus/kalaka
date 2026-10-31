<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

final class NotFound extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'A keresett elem nem található.';
    }

    public function errorCode(): string
    {
        return 'kalaka_not_found';
    }

    public function status(): int
    {
        return 404;
    }
}
