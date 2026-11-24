<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The glue between the answer act and the one seat rule: an offer's seat is a seat in the offer's own pool, so
 * a refusal frees it (`Seats::release()`), and a "yes" after a refusal takes one if one is free and goes over
 * the limit otherwise (`takeIfFree()`, then `takeOver()`: the answer stands, the pool stands over, and the act
 * says so). [UNAPPROVED N5]
 */
final class SeatOfferSeats implements OfferSeats
{
    public function __construct(private readonly Seats $seats = new Seats())
    {
    }

    public function lockPool(SeatPool $pool, Context $context): void
    {
        $this->seats->lock($pool, $context);
    }

    public function release(RegistrationKey $registration, SeatPool $pool, Context $context): void
    {
        $this->seats->release($pool, $registration, $context);
    }

    public function take(RegistrationKey $registration, SeatPool $pool, Context $context): bool
    {
        if ($this->seats->takeIfFree($pool, $registration, SeatAct::Answer, $context)) {
            return true;
        }
        $this->seats->takeOver($pool, $registration, SeatAct::Answer, $context);

        return false;
    }

    public function isFull(RegistrationKey $registration, SeatPool $pool): bool
    {
        return $this->seats->left($pool) === 0;
    }
}
