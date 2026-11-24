<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * What staff are told before they place a registration into an option by hand (`PlaceRegistration`): the
 * option is already full, or the registration does not meet its eligibility condition. Placing checks neither
 * (staff override), so these are warnings, never refusals. A plain read of the current facts, for a screen or
 * the command to ask before the act, and only after the same checks the placing makes (`PlacementLookup`): a
 * placement the placing would refuse gives no warnings.
 *
 * Full: the option has a capacity, and the confirmed assignments to it, not counting this registration's own
 * (it may already hold the option), have reached it. A proposed assignment holds no place until staff confirm
 * it. Eligible: the option's condition read against the registration's current field values and the event's
 * keys and the act's moment (`EventValues::now()`), the facts the condition was saved against
 * (`SaveAssignmentOption`). A condition that cannot be read is a broken system, not a pass: the failure is not
 * caught here.
 */
final class PlacementWarnings
{
    public const FULL = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.';
    public const NOT_ELIGIBLE = 'A jelentkező nem felel meg a lehetőség feltételeinek.';

    public function __construct(
        private readonly PlacementLookup $lookup = new PlacementLookup(),
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * The warnings for placing the registration into the option, in the order full, not eligible; none when
     * the placing would refuse the placement (an unknown registration, another event's set, or an unknown
     * option).
     *
     * @param \DateTimeImmutable $now the act's moment, the one clock passed down: the facts of a `now` condition
     * @return list<string>
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure an invalid condition, or a key the facts lack
     */
    public function of(RegistrationKey $registration, int $setId, string $optionKey, \DateTimeImmutable $now): array
    {
        try {
            $target = $this->lookup->find($registration, $setId, $optionKey);
        } catch (NotFound | InvalidInput) {
            return [];
        }
        $option = $target->option;
        $warnings = [];
        $full = $option->capacity !== null
            && $this->confirmedOthers($registration, $setId, $optionKey) >= $option->capacity;
        if ($full) {
            $warnings[] = self::FULL;
        }
        if ($option->condition !== null && !$this->eligible($registration, $option->condition, $now)) {
            $warnings[] = self::NOT_ELIGIBLE;
        }

        return $warnings;
    }

    private function confirmedOthers(RegistrationKey $registration, int $setId, string $optionKey): int
    {
        $count = 0;
        foreach ($this->assignments->holding($registration->eventId, $setId, $optionKey) as $held) {
            if ($held->state === AssignmentState::Confirmed && $held->registration->code !== $registration->code) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<array-key, mixed> $condition */
    private function eligible(RegistrationKey $registration, array $condition, \DateTimeImmutable $now): bool
    {
        $event = (new Events())->find($registration->eventId);
        if ($event === null) {
            return true;
        }
        $values = (new Forms())->participantValues($registration->eventId, $this->registrations->values($registration));

        return (new Availability())->holds(
            $condition,
            new ValueContext($values + EventValues::of($event) + EventValues::now($now))
        );
    }
}
