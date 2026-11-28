<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The manual confirmation flag of a registration (money.md, "Confirmation": "may come without paying"), a
 * fact: set with a reason, carrying its own set time, and kept until staff unconfirm the registration
 * (`kalaka/unconfirm`), which revokes it. A revoke keeps the row with its revoke time; a new set replaces it.
 */
final class ManualConfirmationRecord
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public \DateTimeImmutable $setAt,
        public ?int $setBy,
        public string $reason,
        public ?\DateTimeImmutable $revokedAt = null,
        public int $version = 0,
    ) {
    }

    public function isSet(): bool
    {
        return $this->revokedAt === null;
    }
}
