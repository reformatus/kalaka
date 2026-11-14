<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\ValueType;

/** Outside a form, a condition reads the keys a fact source (a value context, say) declares. */
final readonly class ContextScope implements ConditionScope
{
    public function __construct(private Facts $context)
    {
    }

    public function typeOf(string $key): ValueType
    {
        return $this->context->get($key)->type;
    }

    public function choices(string $key): ?array
    {
        return null;
    }
}
