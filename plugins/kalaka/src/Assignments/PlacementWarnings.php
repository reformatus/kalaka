<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\Seats;

/**
 * What staff are told before they place a registration into an option by hand (`PlaceRegistration`): the
 * option is already full. Placing does not check it (staff override), so this is a warning, never a
 * refusal. A plain read of the current facts, for a screen or the command to ask before the act, and only
 * after the same checks the placing makes (`PlacementLookup`): a placement the placing would refuse gives no
 * warnings.
 *
 * Full: the registration would take a seat (it is `registered`), the option has a limit, and the seats held in
 * it (one per confirmed assignment), not counting this registration's own (it may already hold the option),
 * have reached it. A registration that takes no seat is not warned. A proposed assignment holds no seat
 * until staff confirm it. Who may pick an option is decided in the form, so nothing here reads a condition.
 */
final class PlacementWarnings
{
    public const FULL = 'Ez a lehetőség már betelt. Ha ide sorolod a jelentkezőt, a létszám túllépi a keretet.';

    public function __construct(
        private readonly PlacementLookup $lookup = new PlacementLookup(),
        private readonly Seats $seats = new Seats(),
        private readonly Registrations $registrations = new Registrations(),
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
        $warnings = [];
        $pool = new OptionSeatPool($registration->eventId, $setId, $optionKey);
        if (
            $this->registrations->find($registration)?->status->isLive() === true
            && $this->seats->wouldOverfill($pool, $registration)
        ) {
            $warnings[] = self::FULL;
        }

        return $warnings;
    }
}
