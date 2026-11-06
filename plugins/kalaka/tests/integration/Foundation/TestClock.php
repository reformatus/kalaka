<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Clock;

/** A clock for tests: fixed, or advancing one second on each call. */
final class TestClock implements Clock
{
    public int $calls = 0;

    public function __construct(
        private readonly \DateTimeImmutable $start = new \DateTimeImmutable('2026-11-30 08:00:00', new \DateTimeZone('UTC')),
        private readonly bool $advances = false,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        $this->calls++;

        return $this->advances ? $this->start->modify('+' . ($this->calls - 1) . ' seconds') : $this->start;
    }
}
