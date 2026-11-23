<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

use Kalaka\Assignments\AssignmentRepository;
use Kalaka\Assignments\OptionSeatPool;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;

/**
 * The seats of a registration's confirmed assignments when it comes back to `registered` (an admission from the
 * waitlist, a restore). Leaving frees every seat (`Seats::releaseAll()`) and the confirmed assignment stays as a
 * record; this takes the seats again, one per confirmed assignment, when the option has one free. An option that
 * is full gives no seat and no error: the assignment stays without it, and the caller (the admission or restore
 * use case) tells staff with the "full" warning, as for an offer. A proposed assignment holds none. Nobody is
 * moved out of a seat to make room. [UNAPPROVED N11]
 */
final class AssignmentSeats
{
    public function __construct(
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly Seats $seats = new Seats(),
    ) {
    }

    /**
     * Takes the seat of each confirmed assignment of the registration, in set order. The registration's row must
     * be held (`Registrations::lock()`) and the registration `registered` by this act.
     *
     * @return list<Assignment> the confirmed assignments whose option was full: no seat taken for them
     */
    public function retake(RegistrationKey $registration, SeatAct $act, Context $context): array
    {
        $full = [];
        foreach ($this->assignments->ofRegistration($registration) as $record) {
            if ($record->state !== AssignmentState::Confirmed) {
                continue;
            }
            $pool = new OptionSeatPool($registration->eventId, $record->setId, $record->optionKey);
            if (!$this->seats->takeIfFree($pool, $registration, $act, $context)) {
                $full[] = $record->toAssignment();
            }
        }

        return $full;
    }
}
