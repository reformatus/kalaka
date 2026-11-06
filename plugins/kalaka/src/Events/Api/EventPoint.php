<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

/** An admin's own timeline point of an event; `day` is local 00:00 of its day in the site's time zone. */
final readonly class EventPoint
{
    public function __construct(public int $id, public string $name, public \DateTimeImmutable $day)
    {
    }
}
