<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Events\EventQuery;
use Kalaka\Events\EventRepository;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;

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
     * Reads the event `FOR UPDATE`, the first lock of a run that changes what belongs to the event.
     *
     * @throws NotFound
     */
    public function lock(Context $context, int $id): Event
    {
        return ($this->repository->findForUpdate($id, $context) ?? throw new NotFound())->toEvent();
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
