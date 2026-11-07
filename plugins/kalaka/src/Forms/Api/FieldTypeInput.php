<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** What a field type is given to check: the value as sent and the block's attributes. */
final readonly class FieldTypeInput
{
    /** @param array<string, mixed> $attributes */
    public function __construct(
        public mixed $value,
        public array $attributes,
    ) {
    }
}
