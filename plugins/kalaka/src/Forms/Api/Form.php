<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A read of a published form: its settings and its version, the ID of its latest revision.
 * The definition is read separately, with `Forms::definition()`. `opens` and `closes` are an additional
 * form's open period as stored: each a date reference in JSON, or null when that end is not set; what
 * they mean is the host's (for an event form, `OpenPeriod`).
 */
final readonly class Form
{
    public function __construct(
        public int $id,
        public int $eventId,
        public FormPlacement $placement,
        public ?string $requiredRole,
        public bool $hasPassword,
        public int $version,
        public ?string $opens = null,
        public ?string $closes = null,
    ) {
    }
}
