<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/** An admin's own timeline point of an event; `at` is its UTC instant. */
final readonly class EventPoint
{
    public function __construct(public int $id, public string $name, public \DateTimeImmutable $at)
    {
    }
}
