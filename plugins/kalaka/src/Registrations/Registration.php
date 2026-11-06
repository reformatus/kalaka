<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/** The stored registration. Both times are UTC instants. */
final class Registration
{
    public function __construct(
        public readonly RegistrationKey $key,
        public RegistrationStatus $status,
        public string $secret,
        public readonly \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $registeredAt = null,
        public int $version = 0,
    ) {
    }
}
