<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

/** A plain mutable entity for the repository tests. */
final class TestItem
{
    public function __construct(
        public string $label = '',
        public ?string $note = null,
        public string $token = '',
        public ?int $regEvent = null,
        public ?string $regCode = null,
        public int $id = 0,
        public int $version = 0,
    ) {
    }
}
