<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * What staff are told before they place a registration into an option by hand (`PlaceRegistration`): the
 * option is already full. Placing does not check it (staff override), so this is a warning, never a
 * refusal. A plain read of the current facts, for a screen or the command to ask before the act, and only
 * after the same checks the placing makes (`PlacementLookup`): a placement the placing would refuse gives no
 * warnings.
 *
 * Full: the option has a capacity, and the confirmed assignments to it, not counting this registration's own
 * (it may already hold the option), have reached it. A proposed assignment holds no place until staff confirm
 * it. Who may pick an option is decided in the form, so nothing here reads a condition.
 */
final class PlacementWarnings
{
    public const FULL = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.';

    public function __construct(
        private readonly PlacementLookup $lookup = new PlacementLookup(),
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
    ) {
    }

    /**
     * The warnings for placing the registration into the option; none when
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
        $full = $option->capacity !== null
            && $this->confirmedOthers($registration, $setId, $optionKey) >= $option->capacity;
        if ($full) {
            $warnings[] = self::FULL;
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
}
