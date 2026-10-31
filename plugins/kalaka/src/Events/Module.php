<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Foundation\Api\Module\AbstractModule;

final class Module extends AbstractModule
{
    public function id(): string
    {
        return 'events';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function boot(): void
    {
    }
}
