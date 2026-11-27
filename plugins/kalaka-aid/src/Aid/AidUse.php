<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Aid\Api\AidInUse;
use Kalaka\Registrations\Api\Registrations;

/**
 * Whether an event's aid is in use: an open (`registered` or `waitlisted`) registration has a request awaiting an
 * answer (`aid_can_pay` filled, no award) or holds an award above zero. A refusal (an award of 0) is not in use,
 * it reduces nothing. Turning aid off asks this first (N120). A plain read of the field values, one statement per
 * open registration, for a rare settings act. [UNAPPROVED N120]
 */
final class AidUse
{
    public function __construct(private readonly Registrations $registrations = new Registrations())
    {
    }

    /** @throws AidInUse */
    public function assertNotInUse(int $eventId): void
    {
        foreach ($this->registrations->openOf($eventId) as $key) {
            $values = $this->registrations->values($key);
            $award = $values[AidComponent::AWARD_FIELD] ?? '';
            $awaits = ($values[AidRequests::CAN_PAY_FIELD] ?? '') !== '' && $award === '';
            if ($awaits || (is_numeric($award) && (float) $award > 0)) {
                throw new AidInUse();
            }
        }
    }
}
