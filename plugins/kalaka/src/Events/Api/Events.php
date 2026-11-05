<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Events\EventRepository;

final class Events
{
    public function __construct(private readonly EventRepository $repository = new EventRepository())
    {
    }

    /** A plain read, without a lock. */
    public function find(int $id): ?Event
    {
        return $this->repository->find($id)?->toEvent();
    }
}
