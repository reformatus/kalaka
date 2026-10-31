<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

class SystemFailure extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'Váratlan hiba történt. Próbáld újra később.';
    }

    public function errorCode(): string
    {
        return 'kalaka_system_failure';
    }

    public function status(): int
    {
        return 500;
    }
}
