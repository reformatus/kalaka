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
 * time-dependent rule counts from it, never from the submit: payment due, price-tier deadlines, everything.
 * Read by the fee components and, later, by every rule that reads registration facts. Built by
 * `Registrations::factsOf()`; it holds values only and reads nothing.
 *
 * TODO(S25, #181): add the field values by participant-field key, typed through
 * `ParticipantFields\Api\ParticipantFields`. Today's participant-field read (`Forms\Api\Forms::participantFields()`)
 * reads posts, so it cannot run inside the evaluation's transaction. Default; re-asked in S25.
 * Decisions join under dotted keys as their slices land.
 */
final readonly class RegistrationFacts implements Facts
{
    public const CODE = 'registration.code';

    /** A choice of one: the status's value. Its display text is empty until a label is approved. */
    public const STATUS = 'registration.status';

    private ValueContext $values;

    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public ?\DateTimeImmutable $registeredAt,
        Event $event,
    ) {
        $this->values = new ValueContext(EventValues::of($event) + [
            self::CODE => Value::text($registration->code, $registration->code),
            self::STATUS => Value::choice([$status->value], ''),
        ]);
    }

    public function get(string $key): Value
    {
        return $this->values->get($key);
    }
}
