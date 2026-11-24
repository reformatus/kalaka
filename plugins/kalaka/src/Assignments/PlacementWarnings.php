<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Seats;

/**
 * What staff are told before they place a registration into an option by hand (`PlaceRegistration`): the
 * option is already full, or the registration does not meet its eligibility condition. Placing checks neither
 * (staff override), so these are warnings, never refusals. A plain read of the current facts, for a screen or
 * the command to ask before the act, and only after the same checks the placing makes (`PlacementLookup`): a
 * placement the placing would refuse gives no warnings.
 *
 * Full: the option has a capacity, and the seats held in it (one per confirmed assignment), not counting this
 * registration's own (it may already hold the option), have reached it. A proposed assignment holds no seat
 * until staff confirm it. Eligible: the option's condition read against the registration's current field
 * values and the event's keys, the facts the condition was saved against (`SaveAssignmentOption`); a
 * condition that cannot be read is not held against the registration, and gives no warning here.
 */
final class PlacementWarnings
{
    public const FULL = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.';
    public const NOT_ELIGIBLE = 'A jelentkező nem felel meg a lehetőség feltételeinek.';

    public function __construct(
        private readonly PlacementLookup $lookup = new PlacementLookup(),
        private readonly Seats $seats = new Seats(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * The warnings for placing the registration into the option, in the order full, not eligible; none when
     * the placing would refuse the placement (an unknown registration, another event's set, or an unknown
     * option).
     *
     * @return list<string>
     */
    public function of(RegistrationKey $registration, int $setId, string $optionKey): array
    {
        try {
            $target = $this->lookup->find($registration, $setId, $optionKey);
        } catch (NotFound | InvalidInput) {
            return [];
        }
        $option = $target->option;
        $warnings = [];
        $pool = new OptionSeatPool($registration->eventId, $setId, $optionKey);
        if ($this->seats->wouldOverfill($pool, $registration)) {
            $warnings[] = self::FULL;
        }
        if ($option->condition !== null && !$this->eligible($registration, $option->condition)) {
            $warnings[] = self::NOT_ELIGIBLE;
        }

        return $warnings;
    }

    /** @param array<array-key, mixed> $condition */
    private function eligible(RegistrationKey $registration, array $condition): bool
    {
        $event = (new Events())->find($registration->eventId);
        if ($event === null) {
            return true;
        }
        $values = (new Forms())->participantValues($registration->eventId, $this->registrations->values($registration));
        try {
            return (new Availability())->holds($condition, new ValueContext($values + EventValues::of($event)));
        } catch (SystemFailure) {
            return true;
        }
    }
}
