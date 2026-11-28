<?php

declare(strict_types=1);

namespace Kalaka\Assignments\Api;

use Kalaka\Assignments\AssignmentRepository;
use Kalaka\Assignments\OptionSeatPool;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\SeatOutcome;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\Seats;

/**
 * The seats of a registration's confirmed assignments when it comes back to `registered` (an admission from the
 * waitlist, a restore). Leaving frees every seat (`Seats::releaseAll()`) and the confirmed assignment stays as a
 * record; this takes the seats again through `Seats::retake()`, one per confirmed assignment, over the option's limit
 * when it is full (a staff act never refuses), and the caller (the admission or restore use case) tells staff
 * with the "full" warning for each. A proposed assignment holds none. Nobody is moved out of a seat to make
 * room.
 */
final class AssignmentSeats
{
    public function __construct(
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly Seats $seats = new Seats(),
    ) {
    }

    /**
     * The pools of the registration's confirmed assignments, in set order: the seats `retake()` takes. A plain read,
     * for the status acts' report and preview.
     *
     * @return list<SeatPool>
     */
    public function pools(RegistrationKey $registration): array
    {
        $pools = [];
        foreach ($this->assignments->ofRegistration($registration) as $record) {
            if ($record->state === AssignmentState::Confirmed) {
                $pools[] = new OptionSeatPool($registration->eventId, $record->setId, $record->optionKey);
            }
        }

        return $pools;
    }

    /**
     * Takes the seat of each confirmed assignment of the registration, in set order. The registration's row must
     * be held (`Registrations::lock()`) and the registration `registered` by this act.
     *
     * @return list<Assignment> the confirmed assignments whose seat went over the option's limit
     */
    public function retake(RegistrationKey $registration, SeatAct $act, Context $context): array
    {
        $full = [];
        foreach ($this->assignments->ofRegistration($registration) as $record) {
            if ($record->state !== AssignmentState::Confirmed) {
                continue;
            }
            $pool = new OptionSeatPool($registration->eventId, $record->setId, $record->optionKey);
            if ($this->seats->retake($pool, $registration, $act, $context) === SeatOutcome::Over) {
                $full[] = $record->toAssignment();
            }
        }

        return $full;
    }
}
