<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

abstract class AbstractModule implements Module
{
    abstract public function id(): string;

    abstract public function requiresApi(): string;

    abstract public function boot(): void;

    public function schema(): Schema
    {
        return Schema::none();
    }

    public function capabilities(): array
    {
        return [];
    }

    public function contribute(Contributions $to): void
    {
    }
}
