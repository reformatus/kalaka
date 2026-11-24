<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Seats;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\SeatPool;

/**
 * The event's own places. Its pool row is the event row, the first lock of a submit; its seats are the
 * event's counted `registered` registrations, so the status stays the one stored fact (`Api\Seats::held()`) and no
 * seat row is written. Whether a submit lands on the waitlist stays the latch's decision
 * (registrations-and-identity.md, "Status, capacity and waitlist"). [UNAPPROVED N2]
 */
final class EventSeatPool implements SeatPool
{
    public function __construct(
        private readonly int $eventId,
        private readonly Events $events = new Events(),
        private readonly EventCapacity $capacity = new EventCapacity(),
    ) {
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function key(): string
    {
        return self::EVENT;
    }

    public function lockLevel(): LockLevel
    {
        return LockLevel::Event;
    }

    public function lockRow(): array
    {
        return ['kalaka_events', [$this->eventId]];
    }

    public function limit(): ?int
    {
        return ($this->events->find($this->eventId) ?? throw new NotFound())->capacity;
    }

    public function lock(Context $context): ?int
    {
        return $this->capacity->lockForRegistration($context, $this->eventId)->capacity;
    }
}
