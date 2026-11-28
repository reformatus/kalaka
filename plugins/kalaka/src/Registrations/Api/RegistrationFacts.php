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
 * due, price-tier deadlines, everything.
 * Read by the fee components and, later, by every rule that reads registration facts. Built by
 * `Registrations::factsOf()`; it holds values only and reads nothing.
 *
 * The field values are typed by `Forms\Api\Forms::participantFacts()` (S25): every participant field of
 * the event is declared, an unanswered or retired one as `Value::none()` of its type, and each field's kind
 * (its block type and a number field's decimals) is read with `fieldKind()` (N19). Decisions join under
 * dotted keys as their slices land.
 */
final readonly class RegistrationFacts implements Facts
{
    public const CODE = 'registration.code';

    /** A choice of one: the status's value. Its display text is empty until a label is approved. */
    public const STATUS = 'registration.status';

    private ValueContext $values;

    /**
     * @param \DateTimeImmutable $now the moment `time.now` reads: the caller's, one clock for the whole read
     * @param array<string, Value> $fieldValues field key → value (`Forms::participantFacts()`)
     * @param array<string, Value> $computed computed values the caller puts in (never stored), dotted keys that
     *     no other key of the source has (`withComputed()`)
     * @param array<string, FieldKind> $fieldKinds field key → its kind (the same call as `$fieldValues`)
     */
    public function __construct(
        public RegistrationKey $registration,
        public RegistrationStatus $status,
        public ?\DateTimeImmutable $registeredAt,
        private Event $event,
        private \DateTimeImmutable $now,
        private array $fieldValues = [],
        private array $computed = [],
        private array $fieldKinds = [],
    ) {
        $this->values = new ValueContext($fieldValues + EventValues::of($event) + EventValues::now($now) + [
            self::CODE => Value::text($registration->code, $registration->code),
            self::STATUS => Value::choice([$status->value], ''),
        ] + $computed);
    }

    /**
     * The same facts with computed values added (foundation.md, "Facts, decisions and computed values": computed
     * values are put into the fact source by the caller, never stored), e.g. a fee component's applied price
     * tier for the later stages.
     *
     * @param array<string, Value> $computed dotted keys
     * @throws \InvalidArgumentException a key that is not dotted or that the source already has
     */
    public function withComputed(array $computed): self
    {
        foreach (array_keys($computed) as $key) {
            if (!str_contains((string) $key, '.') || $this->has((string) $key)) {
                throw new \InvalidArgumentException('A computed value takes a dotted key the facts do not have.');
            }
        }

        return new self(
            $this->registration,
            $this->status,
            $this->registeredAt,
            $this->event,
            $this->now,
            $this->fieldValues,
            [...$this->computed, ...$computed],
            $this->fieldKinds,
        );
    }

    /** Whether the source declares the key: a reader of an optional computed value asks first. */
    public function has(string $key): bool
    {
        return $this->values->has($key);
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
