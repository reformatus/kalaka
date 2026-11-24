<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;

/**
 * What a support offer's seat does when its request is answered (Foundation, "Facts, decisions and computed
 * values"; money.md, "Quota places"). `SeatOfferSeats` is the default of the answer act: it maps each call to
 * the one seat rule (`Seats`), for the pool the request source names (`RequestSource::seatPool()`). The pool
 * is the offer's own: an aid request, and an offer with no limit, have none, and the act makes no seat call.
 * [UNAPPROVED N5]
 */
interface OfferSeats
{
    /**
     * Locks the pool row, first in the run: a pool lock comes before the registration lock, so the answer act
     * calls it before it locks the registration.
     */
    public function lockPool(SeatPool $pool, Context $context): void;

    /** A refusing answer frees the seat the registration holds in the pool; a no-op without one. */
    public function release(RegistrationKey $registration, SeatPool $pool, Context $context): void;

    /**
     * A "yes" after a refusal takes a seat again (B1): a free one, or else one over the limit.
     *
     * @return bool false when the pool was full: the answer stands and the pool goes over its limit
     */
    public function take(RegistrationKey $registration, SeatPool $pool, Context $context): bool;

    /** Whether the offer's pool is full now, for the warning before staff answer "yes" to a refused request. */
    public function isFull(RegistrationKey $registration, SeatPool $pool): bool;
}
