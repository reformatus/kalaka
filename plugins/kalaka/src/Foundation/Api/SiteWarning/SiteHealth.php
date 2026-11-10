<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\SiteWarning;

/**
 * A warning's Site Health test: its ID, and what the test shows while the warning's check finds
 * nothing. Plain text: the adapter escapes it.
 */
final readonly class SiteHealth
{
    public function __construct(
        public string $testId,
        public string $goodLabel,
        public string $goodDescription,
    ) {
    }
}
