<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/** A set manual confirmation flag, as staff see it: when, by whom (a WordPress user ID) and why; UTC. */
final readonly class ManualConfirmation
{
    public function __construct(
        public \DateTimeImmutable $setAt,
        public ?int $setBy,
        public string $reason,
    ) {
    }
}
