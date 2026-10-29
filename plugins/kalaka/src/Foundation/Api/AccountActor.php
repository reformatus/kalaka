<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api;

final readonly class AccountActor implements Actor
{
    public function __construct(public int $userId)
    {
    }

    public function side(): Side
    {
        return Side::Portal;
    }

    public function type(): string
    {
        return 'account';
    }
}
