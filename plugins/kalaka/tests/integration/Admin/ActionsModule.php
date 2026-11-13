<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Foundation\Api\Module\AbstractModule;

/** A module that offers the given actions. */
final class ActionsModule extends AbstractModule
{
    /** @param list<string> $actions */
    public function __construct(private readonly string $id, private readonly array $actions)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function capabilities(): array
    {
        return $this->actions;
    }

    public function boot(): void
    {
    }
}
