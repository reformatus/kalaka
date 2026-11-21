<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The one lookup of a placement by hand (`PlaceRegistration`), so that the placing and its warnings
 * (`PlacementWarnings`) pass the same checks: the registration exists, the option's set is the
 * registration's event's, and the option is the set's. A plain read.
 */
final class PlacementLookup
{
    public function __construct(
        private readonly SetRepository $sets = new SetRepository(),
        private readonly OptionRepository $options = new OptionRepository(),
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    /**
     * @throws NotFound when the registration does not exist, or the set is not the registration's event's
     * @throws InvalidInput when the set has no such option
     */
    public function find(RegistrationKey $registration, int $setId, string $optionKey): PlacementTarget
    {
        if ($this->registrations->find($registration) === null) {
            throw new NotFound();
        }
        $set = $this->sets->find($setId);
        if ($set === null || $set->eventId !== $registration->eventId) {
            throw new NotFound();
        }
        $option = $this->options->find(['setId' => $set->id, 'key' => $optionKey]);
        if ($option === null) {
            throw new InvalidInput();
        }

        return new PlacementTarget($registration, $set->id, $option);
    }
}
