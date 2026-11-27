<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

/**
 * What the grid knows of one participant field (S37): its type (the field block's name), its title as plain
 * text, its options (value → label, retired ones included), whether it is required for the sum row, and the
 * value type its contributed field type gives (null when the type is not contributed).
 */
final readonly class FieldDefinition
{
    /** @param array<string, string> $options */
    public function __construct(
        public string $key,
        public string $type,
        public string $title,
        public array $options,
        public bool $required,
        public ?string $valueType,
    ) {
    }
}
