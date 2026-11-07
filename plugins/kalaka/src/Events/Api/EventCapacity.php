<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Events\EventRepository;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;

/** What a registration's capacity check needs of the event row: the lock and the "full" latch. */
final class EventCapacity
{
    public function __construct(private readonly EventRepository $repository = new EventRepository())
    {
    }

    /**
     * Reads the event `FOR UPDATE`: the first lock of a submit.
     *
     * @throws NotFound
     */
    public function lockForRegistration(Context $context, int $eventId): Event
    {
        $record = $this->repository->findForUpdate($eventId, $context) ?? throw new NotFound();

        return $record->toEvent();
    }

    /**
     * Sets the latch in the calling use case, reading the event `FOR UPDATE`: the event row must be the
     * run's first lock, as `lockForRegistration()` takes it. An event that is already full is left as it is.
     *
     * @throws NotFound
     */
    public function markFull(Context $context, int $eventId): void
    {
        $record = $this->repository->findForUpdate($eventId, $context) ?? throw new NotFound();
        if ($record->isFull) {
            return;
        }

        $record->isFull = true;
        $this->repository->upsert($record, $context);
    }
}
