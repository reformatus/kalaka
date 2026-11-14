<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** A condition reads the keys of a declaration (key → type), e.g. when it is saved, before any fact exists. */
final readonly class DeclaredScope implements ConditionScope
{
    /** @param array<string, ValueType> $declared */
    public function __construct(private array $declared)
    {
    }

    public function typeOf(string $key): ValueType
    {
        if (!isset($this->declared[$key])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('unknown_value_key ' . $key);
        }

        return $this->declared[$key];
    }

    public function choices(string $key): ?array
    {
        return null;
    }
}
