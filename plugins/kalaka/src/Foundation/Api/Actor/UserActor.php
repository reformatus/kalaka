<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Actor;

final readonly class UserActor implements Actor
{
    public function __construct(public int $userId)
    {
    }

    public function side(): Side
    {
        return Side::Admin;
    }

    public function type(): string
    {
        return 'user';
    }
}
