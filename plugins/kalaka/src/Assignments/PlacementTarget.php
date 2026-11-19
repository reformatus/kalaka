<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A checked placement by hand (`PlacementLookup`): the registration staff would place, its set and the
 * option, so the placing (`PlaceRegistration`) and its warnings (`PlacementWarnings`) read one lookup's
 * answer.
 */
final class PlacementTarget
{
    public function __construct(
        public readonly RegistrationKey $registration,
        public readonly int $setId,
        public readonly OptionRecord $option,
    ) {
    }
}
