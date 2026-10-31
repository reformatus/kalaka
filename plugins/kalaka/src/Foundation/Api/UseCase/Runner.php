<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Actor\Actor;

/** Implemented with the runner. */
final class Runner
{
    /** @param array<string, mixed> $input */
    public function run(UseCase $useCase, array $input, Actor $actor): mixed
    {
        throw new \LogicException('Not implemented yet.');
    }
}
