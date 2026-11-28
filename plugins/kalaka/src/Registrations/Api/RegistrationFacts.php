<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\FieldKind;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;

/**
 * A registration's fact source after submit (foundation.md, "Facts, decisions and computed values"; the
 * one availability base, S76): the event's values, the registration's field values by participant-field
 * key, its code and status, and when it last became `registered` (null while it is not; a waitlisted
 * registration is not registered). Every time-dependent rule counts from it, never from the submit: payment
 * due, price-tier deadlines, everything. Payment due itself counts from the later of it and the last
 * changed answer to a request (`lastAnsweredAt`), and has none while a request awaits an answer
 * (`paymentDue()`, so every caller gets the same date). Read by the fee components and, later, by every
 * rule that reads registration facts. Built by `Registrations::factsOf()`; it holds values only and reads
 * nothing. `registration.awaits_answer` is computed there (`Requests::awaits()`), never stored.
 *
 * The field values are typed by `Forms\Api\Forms::participantFacts()` (S25): every participant field of
 * the event is declared, an unanswered or retired one as `Value::none()` of its type, and each field's kind
 * (its block type and a number field's decimals) is read with `fieldKind()` (N19). Decisions join under
 * dotted keys as their slices land.
 */
final readonly class RegistrationFacts implements Facts
{
    public const CODE = 'registration.code';

    /** A choice of one: the status's value; its display text is the status's word, the one a mail shows too. */
    public const STATUS = 'registration.status';

    /** A choice of one, `yes` or `no`: a request has no answer yet. It has no display text. */
    public const AWAITS_ANSWER = 'registration.awaits_answer';

    private ValueContext $values;

    /**
     * @param \DateTimeImmutable $now the moment `time.now` reads: the caller's, one clock for the whole read
     * @param array<string, Value> $fieldValues field key → value (`Forms::participantFacts()`)
     * @param array<string, FieldKind> $fieldKinds field key → its kind (the same call)
     */
    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public ?\DateTimeImmutable $registeredAt,
        private Event $event,
        \DateTimeImmutable $now,
        array $fieldValues = [],
        private array $fieldKinds = [],
        public bool $awaitsAnswer = false,
        public ?\DateTimeImmutable $lastAnsweredAt = null,
    ) {
        $this->values = new ValueContext($fieldValues + EventValues::of($event) + EventValues::now($now) + [
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

    /**
     * The keys a registration's facts declare for the event, with their types: what a condition read over
     * them is checked against when it is saved (`Forms\Api\Availability::check()`).
     *
     * @param array<string, Value> $fieldValues field key → value, e.g. every field as none
     * @return array<string, ValueType>
     */
    public static function declared(Event $event, array $fieldValues): array
    {
        $types = [];
        foreach ($fieldValues as $key => $value) {
            $types[$key] = $value->type;
        }
        $types += EventValues::TYPES;

        return $types + [self::CODE => ValueType::Text, self::STATUS => ValueType::Choice];
    }

    /** The kind of the participant field with that key; null for a key that is no participant field. */
    public function fieldKind(string $key): ?FieldKind
    {
        return $this->fieldKinds[$key] ?? null;
    }

    public function get(string $key): Value
    {
        return $this->values->get($key);
    }
}
