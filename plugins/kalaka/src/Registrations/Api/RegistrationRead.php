<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * A read of one registration for another module: its key, its status and the time it last became
 * `registered` (a UTC instant; null while it never was, kept after a registration leaves), the act that did
 * it (`registeredBy`) and its owner account (a WordPress user ID; null while it has none). Never the secret.
 */
final readonly class RegistrationRead
{
    public function __construct(
        public RegistrationKey $key,
        public RegistrationStatus $status,
        public ?\DateTimeImmutable $registeredAt,
        public ?int $ownerUserId = null,
        public ?RegisteredBy $registeredBy = null,
    ) {
    }
}
