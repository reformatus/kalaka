<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Seed;

/** The seed stopped: a refusal or a failed step. The message is what the command prints after "Error: ". */
final class SeedFailure extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
