<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Events\EventQuery;
use Kalaka\Events\EventRepository;

final class Events
{
    public function __construct(
        private readonly EventRepository $repository = new EventRepository(),
        private readonly EventQuery $query = new EventQuery(),
    ) {
    }

    /** A plain read, without a lock. */
    public function find(int $id): ?Event
    {
        return $this->repository->find($id)?->toEvent();
    }

    /**
     * The events whose stored state is `published`, ascending by id, in one statement.
     *
     * @return list<Event>
     */
    public function published(): array
    {
        return $this->query->published();
    }
}
