<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;

/** The stand-in for slice A's seat rule: no offer has a limit, so nothing is released, nothing is full. */
final class NoOfferSeats implements OfferSeats
{
    public function lockPool(string $pool, Context $context): void
    {
    }

    public function release(RegistrationKey $registration, string $pool, Context $context): void
    {
    }

    public function take(RegistrationKey $registration, string $pool, Context $context): bool
    {
        return true;
    }

    public function isFull(RegistrationKey $registration, string $pool): bool
    {
        return false;
    }
}
