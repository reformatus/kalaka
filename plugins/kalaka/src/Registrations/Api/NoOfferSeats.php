<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;

/** The stand-in for slice A's seat rule: no offer has a limit, so nothing is released, nothing is full. */
final class NoOfferSeats implements OfferSeats
{
    public function lockPool(Request $request, Context $context): void
    {
    }

    public function release(RegistrationKey $registration, Request $request, Context $context): void
    {
    }

    public function take(RegistrationKey $registration, Request $request, Context $context): bool
    {
        return true;
    }

    public function isFull(RegistrationKey $registration, Request $request): bool
    {
        return false;
    }
}
