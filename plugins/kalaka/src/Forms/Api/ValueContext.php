<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The declared keys of a document and their values: built by whoever renders the document. A key
 * is a field key or a dotted `<group>.<name>` (or `<group>.<name>.<id>`: the event's custom points, and a
 * pool's places left by its option value); each key's type is known even when its value is `none`.
 * It is the plain fact source: a map of keys to values.
 */
final readonly class ValueContext implements Facts
{
    /** An option value's grammar (`KeyDerivation::VALUE_PATTERN` without its length): a key's third part. */
    public const OPTION_VALUE = '[a-z0-9][a-z0-9_-]*';

    private const KEY_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*(\.' . self::OPTION_VALUE . ')?)?\z/';

    /** Whether the text is a value key: a field key, `<group>.<name>` or `<group>.<name>.<id or option value>`. */
    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /** @param array<string, Value> $values key → value */
    public function __construct(private array $values = [])
    {
        foreach (array_keys($values) as $key) {
            if (!self::isKey((string) $key)) {
                throw new \InvalidArgumentException('A value key is a field key or a dotted group.name.');
            }
        }
    }

    /** The same context with one more key, or that key's value replaced. */
    public function with(string $key, Value $value): self
    {
        return new self([...$this->values, $key => $value]);
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /** @throws SystemFailure when the key is not declared (problem `unknown_value_key`) */
    public function get(string $key): Value
    {
        if (!isset($this->values[$key])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_value_key ' . $key);
        }

        return $this->values[$key];
    }
}
