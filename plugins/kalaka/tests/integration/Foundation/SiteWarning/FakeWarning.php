<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\SiteWarning;

use Kalaka\Foundation\Api\SiteWarning\SiteHealth;
use Kalaka\Foundation\Api\SiteWarning\Shown;
use Kalaka\Foundation\Api\SiteWarning\SiteWarning;

/** A warning whose check returns what the test gives, or throws it; it counts its checks. */
final class FakeWarning implements SiteWarning
{
    public int $checks = 0;

    public function __construct(
        private readonly string $id,
        private readonly Shown|\Throwable|null $result,
        private readonly ?SiteHealth $health = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function check(): ?Shown
    {
        $this->checks++;
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }

    public function siteHealth(): ?SiteHealth
    {
        return $this->health;
    }
}
