<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Actor;

use Kalaka\Registrations\Api\RegistrationKey;

/** Never carries the secret itself. */
final readonly class SecretLinkActor implements Actor
{
    public function __construct(public RegistrationKey $registration)
    {
    }

    public function side(): Side
    {
        return Side::Portal;
    }

    public function type(): string
    {
        return 'secret_link';
    }
}
