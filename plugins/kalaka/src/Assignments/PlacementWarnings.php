<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * What staff are told before they place a registration into an option by hand (`PlaceRegistration`): the
 * option is already full, or the registration does not meet its eligibility condition. Placing checks neither
 * (staff override), so these are warnings, never refusals. A plain read of the current facts, for a screen or
 * the command to ask before the act.
 *
 * Full: the option has a capacity, and the confirmed assignments to it, not counting this registration's own
 * (it may already hold the option), have reached it. A proposed assignment holds no place until staff confirm
 * it. Eligible: the option's condition read against the registration's current field values and the event's
 * keys, the facts the condition was saved against (`SaveAssignmentOption`); a condition that cannot be read
 * is not held against the registration, and gives no warning here.
 */
final class PlacementWarnings
{
    public const FULL = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, túllépi a létszámkeretet.';
    public const NOT_ELIGIBLE = 'A jelentkező nem felel meg a lehetőség feltételeinek.';

    public function __construct(
        private readonly OptionRepository $options = new OptionRepository(),
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * The warnings for placing the registration into the option, in the order full, not eligible; none when
     * the option is unknown (the placing refuses it).
     *
     * @return list<string>
     */
    public function of(RegistrationKey $registration, int $setId, string $optionKey): array
    {
        $option = $this->options->find(['setId' => $setId, 'key' => $optionKey]);
        if ($option === null) {
            return [];
        }
        $warnings = [];
        $full = $option->capacity !== null
            && $this->confirmedOthers($registration, $setId, $optionKey) >= $option->capacity;
        if ($full) {
            $warnings[] = self::FULL;
        }
        if ($option->condition !== null && !$this->eligible($registration, $option->condition)) {
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
