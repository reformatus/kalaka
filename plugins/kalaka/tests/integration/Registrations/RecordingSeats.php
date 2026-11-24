<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\OfferSeats;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatPool;

/** A test double of slice A's seat rule: records what the answer act asks of it, and can say the pool is full. */
final class RecordingSeats implements OfferSeats
{
    /** @var list<string> */
    public array $calls = [];

    public bool $full = false;

    public function lockPool(SeatPool $pool, Context $context): void
    {
        $this->calls[] = 'lock:' . $pool->key();
    }

    public function release(RegistrationKey $registration, SeatPool $pool, Context $context): void
    {
        $this->calls[] = 'release:' . $pool->key();
    }

    public function take(RegistrationKey $registration, SeatPool $pool, Context $context): bool
    {
        $this->calls[] = 'take:' . $pool->key();

        return !$this->full;
    }

    public function isFull(RegistrationKey $registration, SeatPool $pool): bool
    {
        return $this->full;
    }
}
