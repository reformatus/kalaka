<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Actor;

final readonly class SystemActor implements Actor
{
    public function side(): Side
    {
        return Side::Admin;
    }

    public function type(): string
    {
        return 'system';
    }
}
