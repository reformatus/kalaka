<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

final class PermissionDenied extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Ehhez nincs jogosultságod.';
    }

    public function errorCode(): string
    {
        return 'kalaka_permission_denied';
    }

    public function status(): int
    {
        return 403;
    }
}
