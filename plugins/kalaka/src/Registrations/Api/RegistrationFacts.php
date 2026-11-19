<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;

/**
 * A registration's fact source after submit (foundation.md, "Facts, decisions and computed values"; the
 * one availability base, S76): the event's values, the registration's field values by participant-field
 * key, its code and status, and when it last became `registered` (null while it is not; a waitlisted
 * registration is not registered). Every time-dependent rule counts from it, never from the submit: payment
 * due, price-tier deadlines, everything.
 * Read by the fee components and, later, by every rule that reads registration facts. Built by
 * `Registrations::factsOf()`; it holds values only and reads nothing.
 *
 * The field values are typed by `Forms\Api\Forms::participantValues()` (S25): every participant field of
 * the event is declared, an unanswered or retired one as `Value::none()` of its type. Decisions join under
 * dotted keys as their slices land.
 */
final readonly class RegistrationFacts implements Facts
{
    public const CODE = 'registration.code';

    /** A choice of one: the status's value. Its display text is empty until a label is approved. */
    public const STATUS = 'registration.status';

    private ValueContext $values;

    /** @param array<string, Value> $fieldValues field key → value (`Forms::participantValues()`) */
    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public ?\DateTimeImmutable $registeredAt,
        Event $event,
        array $fieldValues = [],
    ) {
        $this->values = new ValueContext($fieldValues + EventValues::of($event) + EventValues::now() + [
            self::CODE => Value::text($registration->code, $registration->code),
            self::STATUS => Value::choice([$status->value], ''),
        ]);
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

    public function get(string $key): Value
    {
        return $this->values->get($key);
    }
}
