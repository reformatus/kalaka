<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The declared keys of a document and their values: built by whoever renders the document. A key
 * is a field key or a dotted `<group>.<name>`; each key's type is known even when its value is `none`.
 */
final readonly class ValueContext
{
    private const KEY_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)?\z/';

    /** @param array<string, Value> $values key → value */
    public function __construct(private array $values = [])
    {
        foreach (array_keys($values) as $key) {
            if (preg_match(self::KEY_PATTERN, (string) $key) !== 1) {
                throw new \InvalidArgumentException('A value key is a field key or a dotted group.name.');
            }
        }
    }

    /** The same context with one more key, or that key's value replaced. */
    public function with(string $key, Value $value): self
    {
        return new self([...$this->values, $key => $value]);
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
