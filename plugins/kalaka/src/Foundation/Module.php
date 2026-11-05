<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Clock\WordPressClock;

final class Module extends AbstractModule
{
    public function id(): string
    {
        return 'foundation';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function boot(): void
    {
        Runner::publish(new Runner(new WordPressClock()));
    }
}
