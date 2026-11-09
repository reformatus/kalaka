<?php

declare(strict_types=1);

namespace Kalaka\Events\Api;

use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Money\Currency;

/** A read of an event. The points are UTC instants. */
final readonly class Event
{
    /** @param list<EventPoint> $customPoints */
    public function __construct(
        public int $id,
        public int $version,
        public string $name,
        private EventState $storedState,
        public Currency $currency,
        public ?int $capacity,
        public bool $isFull,
        public ?\DateTimeImmutable $registrationOpens,
        public ?\DateTimeImmutable $registrationCloses,
        public ?\DateTimeImmutable $start,
        public ?\DateTimeImmutable $end,
        public array $customPoints,
    ) {
    }

    /** The state as seen: a published event reads as unpublished while the site is closed. */
    public function state(): EventState
    {
        return $this->storedState === EventState::Published && !LoadState::current()->isOpen()
            ? EventState::Unpublished
            : $this->storedState;
    }

    /** Whether the event is published and `$now` is from the opening instant (included) to the closing one (excluded). */
    public function registrationWindowContains(\DateTimeImmutable $now): bool
    {
        if (
            $this->state() !== EventState::Published
            || $this->registrationOpens === null
            || $this->registrationCloses === null
        ) {
            return false;
        }

        // the window ends exactly at the closing point, with no added day
        return $this->registrationOpens <= $now && $now < $this->registrationCloses;
    }
}
