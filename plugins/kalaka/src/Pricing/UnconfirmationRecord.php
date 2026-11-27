<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Staff's latest unconfirm of a registration (the owner's ruling 2026-11-19), a fact: when (UTC), by whom and
 * why. The confirmation rule unconfirms a `confirmed` registration whose current record is older than it;
 * an earlier unconfirm is in the audit log.
 */
final class UnconfirmationRecord
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public \DateTimeImmutable $unconfirmedAt,
        public ?int $unconfirmedBy,
        public string $reason,
        public int $version = 0,
    ) {
    }
}
