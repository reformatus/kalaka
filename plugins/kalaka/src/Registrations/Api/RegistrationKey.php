<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

final readonly class RegistrationKey
{
    public function __construct(public int $eventId, public string $code)
    {
    }
}
