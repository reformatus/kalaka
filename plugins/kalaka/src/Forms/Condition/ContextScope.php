<?php

declare(strict_types=1);

namespace Kalaka\Forms\Condition;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;

/** Outside a form, a condition reads the keys a value context declares. */
final readonly class ContextScope implements ConditionScope
{
    public function __construct(private ValueContext $context)
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
