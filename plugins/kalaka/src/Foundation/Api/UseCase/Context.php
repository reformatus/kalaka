<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;

/** What a running use case gets from the runner. Implemented with the runner. */
final class Context
{
    public function actor(): Actor
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function now(): \DateTimeImmutable
    {
        throw new \LogicException('Not implemented yet.');
    }

    /**
     * Runs a nested use case.
     *
     * @param array<string, mixed> $input
     */
    public function run(UseCase $useCase, array $input): mixed
    {
        throw new \LogicException('Not implemented yet.');
    }

    public function afterCommit(callable $step): void
    {
        throw new \LogicException('Not implemented yet.');
    }
}
