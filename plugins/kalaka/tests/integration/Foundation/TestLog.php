<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

/** A plain append-only record for the repository tests. */
final class TestLog
{
    public function __construct(
        public string $label = '',
        public ?int $regEvent = null,
        public ?string $regCode = null,
        public int $id = 0,
    ) {
    }
}
