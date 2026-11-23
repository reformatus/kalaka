<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The seam to the seat rule of slice A (Foundation, "Facts, decisions and computed values"; money.md, "Quota
 * places"): what a support offer's seat does when its request is answered. Slice B does not reimplement seats;
 * `NoOfferSeats` stands in until slice A is merged, and the seat rule then implements this and becomes the
 * default of `AnswerRequest`.
 *
 * TODO(slice A): bind to the seat rule's `Seats` (release a seat, take one under the pool's lock, whether the
 * pool is full). [UNAPPROVED N4]
 */
interface OfferSeats
{
    /**
     * Locks the offer's pool row, first in the run: a pool lock comes before the registration lock, so the answer
     * act calls it before it locks the registration.
     */
    public function lockPool(Request $request, Context $context): void;

    /** A refusing answer frees the seat the registration holds for the offer; a no-op without one. */
    public function release(RegistrationKey $registration, Request $request, Context $context): void;

    /**
     * A "yes" after a refusal takes a seat again if one is free (B1).
     *
     * @return bool false when the pool is full: the answer stands and the pool goes over its limit
     */
    public function take(RegistrationKey $registration, Request $request, Context $context): bool;

    /** Whether the offer's pool is full now, for the warning before staff answer "yes" to a refused request. */
    public function isFull(RegistrationKey $registration, Request $request): bool;
}
