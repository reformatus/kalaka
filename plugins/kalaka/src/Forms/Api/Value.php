<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A typed value: its type, its data (what a condition compares) and its text (what renders, plain
 * text, given by whoever makes the value). Data: number a decimal string, date `YYYY-MM-DD`, datetime an
 * instant as `YYYY-MM-DDTHH:MM:SS` with its offset (`Z` or `+HH:MM`), text a string, choice a list of
 * option values. Built through the named constructors; ill-formed data is a
 * programming error (`\InvalidArgumentException`). `none()` is a declared value that is empty.
 */
final readonly class Value
{
    /**
     * @param string|list<string>|null $data null for a value that is `none`
     * @param \DateTimeZone|null $zone a datetime's wall-clock zone, when it has one (the site's): a date reference
     *     moves its wall time there, so a move across a daylight-saving change keeps the wall time
     */
    private function __construct(
        public ValueType $type,
        public string|array|null $data,
        public string $text,
        public ?\DateTimeZone $zone = null,
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

    /**
     * An instant: `YYYY-MM-DDTHH:MM:SS` and its offset (`Z`, `+HH:MM` or `-HH:MM`, at most 14 hours). The date
     * part is the day it falls on where it was written (the site's zone, for a value the server builds). A
     * day or time that does not exist (30 February, `T24:00:00`) is refused.
     */
    public static function datetime(string $data, string $text, ?\DateTimeZone $zone = null): self
    {
        if (self::instant($data) === null) {
            throw new \InvalidArgumentException('A datetime value holds an instant with its offset.');
        }

        return new self(ValueType::Datetime, $data, $text, $zone);
    }

    /**
     * The instant of a datetime written as `YYYY-MM-DDTHH:MM:SS` and an offset; null when it is not one. Decision
     * N648 (#222): the stored shape of a datetime literal.
     */
    public static function instant(string $data): ?\DateTimeImmutable
    {
        $pattern = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]'
            . '(Z|[+-](0[0-9]|1[0-4]):[0-5][0-9])\z/';
        if (preg_match($pattern, $data) !== 1) {
            return null;
        }
        $instant = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $data);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($instant === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $instant;
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
