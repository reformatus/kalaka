<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A typed value: its type, its data (what a condition compares) and its text (what renders, plain
 * text, given by whoever makes the value). Data: number a decimal string, date `YYYY-MM-DD`, text a
 * string, choice a list of option values. Built through the named constructors; ill-formed data is a
 * programming error (`\InvalidArgumentException`). `none()` is a declared value that is empty.
 */
final readonly class Value
{
    /** @param string|list<string>|null $data null for a value that is `none` */
    private function __construct(
        public ValueType $type,
        public string|array|null $data,
        public string $text,
    ) {
    }

    public static function number(string $data, string $text): self
    {
        if (preg_match('/\A-?[0-9]+(\.[0-9]+)?\z/', $data) !== 1) {
            throw new \InvalidArgumentException('A number value holds a decimal string.');
        }

        return new self(ValueType::Number, $data, $text);
    }

    public static function date(string $data, string $text): self
    {
        if (
            preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $data, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new \InvalidArgumentException('A date value holds a calendar day as YYYY-MM-DD.');
        }

        return new self(ValueType::Date, $data, $text);
    }

    public static function text(string $data, string $text): self
    {
        return new self(ValueType::Text, $data, $text);
    }

    /** @param array<mixed> $data a list of the chosen option values (a single choice is a list of one) */
    public static function choice(array $data, string $text): self
    {
        if (!array_is_list($data)) {
            throw new \InvalidArgumentException('A choice value holds a list of option values.');
        }
        foreach ($data as $option) {
            if (!is_string($option)) {
                throw new \InvalidArgumentException('A choice value holds a list of option values.');
            }
        }

        return new self(ValueType::Choice, $data, $text);
    }

    /** A declared value that is empty: its text is `""`. */
    public static function none(ValueType $type): self
    {
        return new self($type, null, '');
    }

    /** Nothing to compare: `none()`, a text of no characters or a choice of no options. */
    public function isEmpty(): bool
    {
        return $this->data === null || $this->data === '' || $this->data === [];
    }
}
