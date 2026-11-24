<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Registrations\Api\Registrations;

/**
 * A charge an open (`registered` or `waitlisted`) registration chose is not removed (D3, [UNAPPROVED N302], as
 * the price tiers' and the offers' saves do): the save's preview and the save itself refuse, so a line is never
 * reversed behind a registrant's back. A charge no open registration chose may be removed, and its declared field
 * stays declared. A charge is chosen by a positive value of its field: a consent's `1`, a quantity of one or more
 * (`Registrations::openChose()`). A plain read: one per removed charge.
 */
final class ChargeRemovalGuard
{
    public function __construct(private readonly Registrations $registrations = new Registrations())
    {
    }

    /**
     * @param list<string> $stored the keys of the event's charges now
     * @param list<string> $proposed the keys the save keeps
     * @throws RuleViolation when a removed charge is chosen on an open registration
     */
    public function check(int $eventId, array $stored, array $proposed): void
    {
        foreach (array_diff($stored, $proposed) as $key) {
            if ($this->registrations->openChose($eventId, $key)) {
                $error = new RuleViolation();
                throw $error;
            }
        }
    }
}
