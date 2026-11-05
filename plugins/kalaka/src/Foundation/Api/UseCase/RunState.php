<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\UseCase;

use Kalaka\Foundation\Api\Error\KalakaException;

/**
 * What one attempt of an outermost run collects; a retried attempt starts with a fresh one.
 *
 * @internal
 */
final class RunState
{
    /** False once the attempt has ended: a Context kept past that point is refused. */
    public bool $open = true;

    /** The first error of a nested run (or of a second `Runner::run()`): it decides the outcome. */
    public ?KalakaException $failure = null;

    public ?LockLevel $highestLock = null;

    /** @var list<array{string, callable(): void}> use case name and step, in registration order */
    public array $steps = [];
}
