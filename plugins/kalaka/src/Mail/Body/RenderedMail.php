<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

/** One rendered mail: its subject (plain text), its HTML and the version of what it was rendered from. */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $version,
    ) {
    }
}
