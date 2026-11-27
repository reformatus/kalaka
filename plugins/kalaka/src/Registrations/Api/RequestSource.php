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
     * The same question as `requestsOf()` and `isAnswered()` together, stated as data for the admin grid's "awaits
     * an answer" column: an `AwaitingRule` over the registration's field values, true when this source has a
     * request awaiting an answer. It means exactly what the two reads mean: a request the source names now and its
     * answer field empty (the field is the truth). It does not look at the registration's status (core does: only
     * a `registered` registration awaits in the grid). Core compiles the rule to SQL (`awaitingCondition()`) and
     * evaluates it in PHP (`awaits()`); the source writes no SQL and reads no database, only its configuration (which
     * offers need approval). Null when this event's registrations can have no request from the source.
     */
    public function awaitingRule(int $eventId): ?AwaitingRule;

    /**
     * The key of the seat pool an offer's seat comes from, or null when the request has no limit (an aid
     * request, an offer with no quota). The answer act passes it to `OfferSeats`.
     */
    public function seatPool(Request $request): ?string;

    /**
     * What an aid request is measured against, now: the full price the participant has to pay before the aid and
     * what they stated they can pay, so the answer act can refuse an award above the price and the admin read can
     * show both. Null for a request that has no price to compare (an offer) or when none can be read. Reads only the
     * registration's facts and the event's settings. [UNAPPROVED N1133]
     *
     * @param array<string, string> $values the registration's current field values, key → stored value
     */
    public function terms(
        RegistrationKey $registration,
        Request $request,
        array $values,
        \DateTimeImmutable $now,
    ): ?RequestTerms;

    /**
     * Writes the answer to the source's own participant field, inside the answer act's transaction, with
     * `Registrations::writeValue()`. It may refuse an answer it cannot take (an award the field cannot hold)
     * with `InvalidInput`; the answer act itself refuses an award above `terms()`' full price.
     */
    public function writeAnswer(
        RegistrationKey $registration,
        Request $request,
        Answer $answer,
        Context $context,
    ): void;
}
