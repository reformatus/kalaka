<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/** What a field type makes of an answer: not answered, a value to store, or an error message. */
final readonly class FieldAnswer
{
    private function __construct(
        public ?string $value,
        public ?string $error,
    ) {
    }

    public static function notAnswered(): self
    {
        return new self(null, null);
    }

    public static function stored(string $value): self
    {
        return new self($value, null);
    }

    public static function error(string $message): self
    {
        return new self(null, $message);
    }
}
