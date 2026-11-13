<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A read of a published form: its settings and its version, the ID of its latest revision.
 * The definition is read separately, with `Forms::definition()`.
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
    ) {
    }
}
