<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Actor;

/**
 * A person on the portal side who is neither logged in nor holding a secret link: a guest filling in a
 * public form. It carries no identity and may run only a `PublicUseCase`.
 */
final readonly class GuestActor implements Actor
{
    public function side(): Side
    {
        return Side::Portal;
    }

    public function type(): string
    {
        return 'guest';
    }
}
