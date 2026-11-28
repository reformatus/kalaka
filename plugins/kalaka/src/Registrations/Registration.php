<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Registrations\Api\RegisteredBy;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The stored registration. Both times are UTC instants; the owner is a WordPress user ID, null for none;
 * `registeredBy` is the act that last made it `registered`, set only together with `registeredAt`.
 */
final class Registration
{
    public function __construct(
        public readonly RegistrationKey $key,
        public RegistrationStatus $status,
        public string $secret,
        public readonly \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $registeredAt = null,
        public int $version = 0,
        public ?int $ownerUserId = null,
        public ?RegisteredBy $registeredBy = null,
    ) {
    }
}
