<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Registrations\Api\RegistrationFacts;

/**
 * Everything a confirmation threshold gets (modules-and-extensions.md, "Contracts"): the registration's facts,
 * the payer, its target as the fee composition says now, and the run's moment. [UNAPPROVED N5]
 */
final readonly class ConfirmationThresholdInput
{
    public function __construct(
        public RegistrationFacts $facts,
        public string $payer,
        public Money $target,
        public \DateTimeImmutable $now,
    ) {
    }
}
