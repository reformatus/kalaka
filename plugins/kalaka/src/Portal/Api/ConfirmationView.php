<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

/** The current confirmation decision (money.md, "Confirmation"); the time is UTC. */
final readonly class ConfirmationView
{
    public function __construct(
        public bool $confirmed,
        public ?\DateTimeImmutable $effectiveAt,
    ) {
    }
}
