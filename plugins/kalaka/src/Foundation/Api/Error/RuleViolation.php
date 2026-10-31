<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Error;

class RuleViolation extends KalakaException
{
    protected function defaultMessage(): string
    {
        return 'A művelet nem hajtható végre.';
    }

    public function errorCode(): string
    {
        return 'kalaka_rule_violation';
    }

    public function status(): int
    {
        return 422;
    }
}
