<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Registrations\Api\RegistrationFacts;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * Everything a fee component gets (modules-and-extensions.md, "Contracts": one immutable input per point).
 * `facts` is the registration's fact source (S76's one availability base, with `registeredAt`, the anchor of
 * relative dates), `before` the totals per payer of the earlier stages only, `currency` the event's.
 */
final readonly class FeeComponentInput
{
    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public RegistrationFacts $facts,
        public StageTotals $before,
        public Currency $currency,
        public \DateTimeImmutable $now,
    ) {
    }
}
