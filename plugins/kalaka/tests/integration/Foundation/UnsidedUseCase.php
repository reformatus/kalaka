<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\UseCase;

/** Implements only `UseCase`: neither an admin nor a portal use case, so the runner refuses it. */
final class UnsidedUseCase implements UseCase
{
    public int $calls = 0;

    /** @param \Closure(): void $body */
    public function __construct(private readonly \Closure $body)
    {
    }

    public static function name(): string
    {
        return 'kalaka/unsided';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object'];
    }

    public function execute(array $input, Context $context): mixed
    {
        $this->calls++;
        ($this->body)();

        return null;
    }
}
