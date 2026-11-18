<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * A fee component threw or returned something invalid (a malformed payer, another currency). The evaluation
 * turns it into a `SystemFailure` naming the rule `money/<component id>`.
 */
final class FeeComponentFailed extends \RuntimeException
{
    public function __construct(public readonly string $componentId, ?\Throwable $previous = null)
    {
        parent::__construct('Fee component ' . $componentId . ' failed.', 0, $previous);
    }
}
