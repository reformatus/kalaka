<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Condition;

use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;

/** What keys a condition may read where it sits, and of which type; pure. */
interface ConditionScope
{
    /** @throws SystemFailure when the condition may not read the key */
    public function typeOf(string $key): ValueType;

    /**
     * The values a choice key may be compared with, or null when any value is accepted.
     *
     * @return list<string>|null
     */
    public function choices(string $key): ?array;
}
