<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\SiteWarning;

/**
 * What a warning shows. Plain text throughout: the renderer escapes it and builds every bit of
 * markup.
 */
final readonly class Shown
{
    /**
     * @param string $title Hungarian; states the condition, without end punctuation
     * @param string $description Hungarian
     * @param ?string $details may be English; one item per line; shown collapsed under "Részletek"
     */
    public function __construct(
        public Severity $severity,
        public string $title,
        public string $description,
        public ?string $details = null,
    ) {
    }
}
