<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Evaluation;

/** A decision rule failed; the message is the rule's name, `decisions/<kind>`. */
final class RuleFailed extends \RuntimeException
{
    public function __construct(string $rule, ?\Throwable $previous = null)
    {
        parent::__construct($rule, 0, $previous);
    }
}
