<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;

/**
 * A registration's fact source after submit (foundation.md, "Facts, decisions and computed values"; the
 * one availability base, S76): the event's values, the registration's code and status, and when it last
 * became `registered` (null while it is not; a waitlisted registration is not registered). Every
 * time-dependent rule counts from it, never from the submit; payment due counts from the later of it and the
 * last changed answer to a request (`lastAnsweredAt`), and has none while a request awaits an answer
 * (`paymentDue()`, so every caller gets the same date). Read by the fee components and, later, by every rule that
 * reads registration facts. Built by `Registrations::factsOf()`; it holds values only and reads nothing.
 * `registration.awaits_answer` is computed there (`Requests::awaits()`), never stored.
 *
 * TODO(S25, #181): add the field values by participant-field key, typed through
 * `ParticipantFields\Api\ParticipantFields`. Today's participant-field read (`Forms\Api\Forms::participantFields()`)
 * reads posts, so it cannot run inside the evaluation's transaction. Default; re-asked in S25.
 * Decisions join under dotted keys as their slices land.
 */
final readonly class RegistrationFacts implements Facts
{
    public const CODE = 'registration.code';

    /** A choice of one: the status's value; its display text is the status's word, the one a mail shows too. */
    public const STATUS = 'registration.status';

    /** A choice of one, `yes` or `no`: a request has no answer yet. It has no display text. */
    public const AWAITS_ANSWER = 'registration.awaits_answer';

    private ValueContext $values;

    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public ?\DateTimeImmutable $registeredAt,
        private Event $event,
        public bool $awaitsAnswer = false,
        public ?\DateTimeImmutable $lastAnsweredAt = null,
    ) {
        $this->values = new ValueContext(EventValues::of($this->event) + [
            self::CODE => Value::text($registration->code, $registration->code),
            self::STATUS => Value::choice([$status->value], $status->word()),
            self::AWAITS_ANSWER => Value::choice([$awaitsAnswer ? 'yes' : 'no'], ''),
        ]);
    }

    /** The registration's payment due: the event's date type over this fact source (money.md, "Core money scope"). */
    public function paymentDue(): ?\DateTimeImmutable
    {
        return $this->event->paymentDue($this->registeredAt, $this->lastAnsweredAt, $this->awaitsAnswer);
    }

    public function get(string $key): Value
    {
        return $this->values->get($key);
    }
}
