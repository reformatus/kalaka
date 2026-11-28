<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Error\RuleViolation;

/**
 * A use case refused because a fee component refused (`FeeRefusal`): a `RuleViolation` whose text is the
 * component's own for the actor's side.
 */
final class FeeRefused extends RuleViolation
{
    private string $text = '';

    public function __construct(string $text, ?\Throwable $previous = null)
    {
        $this->text = $text;
        parent::__construct(null, $previous);
    }

    protected function defaultMessage(): string
    {
        return $this->text;
    }

    public function errorCode(): string
    {
        return 'kalaka_fee_refused';
    }
}
