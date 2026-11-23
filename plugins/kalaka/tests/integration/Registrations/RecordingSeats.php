<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Registrations;

use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\OfferSeats;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Request;

/** A test double of slice A's seat rule: records what the answer act asks of it, and can say the pool is full. */
final class RecordingSeats implements OfferSeats
{
    /** @var list<string> */
    public array $calls = [];

    public bool $full = false;

    public function lockPool(Request $request, Context $context): void
    {
        $this->calls[] = 'lock:' . $request->id;
    }

    public function release(RegistrationKey $registration, Request $request, Context $context): void
    {
        $this->calls[] = 'release:' . $request->id;
    }

    public function take(RegistrationKey $registration, Request $request, Context $context): bool
    {
        $this->calls[] = 'take:' . $request->id;

        return !$this->full;
    }

    public function isFull(RegistrationKey $registration, Request $request): bool
    {
        return $this->full;
    }
}
