<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\ValueType;

/** What a comparison asks of a value; which are allowed depends on the value's type. */
enum Operator: string
{
    case Equals = 'equals';
    case LessThan = 'lessThan';
    case LessOrEqual = 'lessOrEqual';
    case GreaterThan = 'greaterThan';
    case GreaterOrEqual = 'greaterOrEqual';
    case Contains = 'contains';
    case Empty = 'empty';

    public function isAllowedFor(ValueType $type): bool
    {
        return match ($type) {
            ValueType::Number, ValueType::Date => $this !== self::Contains,
            ValueType::Text, ValueType::Choice => in_array($this, [self::Equals, self::Contains, self::Empty], true),
        };
    }
}
