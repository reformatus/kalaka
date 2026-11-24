<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The extension point behind "answer a request" (modules-and-extensions.md, "The extension surface"): an inside
 * point. A module that has requests (the support offers, the aid extension) says which a registration has now,
 * from its field values, and writes the answer to its own participant field, being its only writer (B3). Core
 * keeps the answer record of the act and the "awaits an answer" read, which asks the source whether its field
 * holds an answer; the source writes no record of its own and fires no hooks. [UNAPPROVED N1] [UNAPPROVED N2]
 */
interface RequestSource
{
    /**
     * The requests the registration has that need an answer, now: an aid request with a positive amount, a
     * chosen offer that needs approval. Reads only what it is given.
     *
     * @param array<string, string> $values the registration's current field values, key → stored value
     * @return list<Request>
     */
    public function requestsOf(RegistrationKey $registration, array $values): array;

    /**
     * Whether the request is answered, read from the source's own answer field in the values it is given (an
     * offer's `<key>_answer`, the aid award: empty is awaiting, a zero is a refusal). The field is the truth;
     * core's answer record decides nothing here. A request re-stated after an answer reads as answered until
     * staff answer again. [UNAPPROVED N136]
     *
     * @param array<string, string> $values the registration's current field values, key → stored value
     */
    public function isAnswered(Request $request, array $values): bool;

    /**
     * The seat pool an offer's seat comes from, or null when the request has no limit (an aid request, an offer
     * with no quota). A plain read. The answer act passes it to `OfferSeats`.
     */
    public function seatPool(RegistrationKey $registration, Request $request): ?SeatPool;

    /**
     * Writes the answer to the source's own participant field, inside the answer act's transaction, with
     * `Registrations::writeValue()`. It may refuse an answer it cannot take (an award above what the
     * registration owes) with `InvalidInput`.
     */
    public function writeAnswer(
        RegistrationKey $registration,
        Request $request,
        Answer $answer,
        Context $context,
    ): void;
}
