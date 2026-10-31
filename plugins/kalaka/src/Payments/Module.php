<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Foundation\Api\Module\AbstractModule;

final class Module extends AbstractModule
{
    public function id(): string
    {
        return 'payments';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function boot(): void
    {
    }
}
