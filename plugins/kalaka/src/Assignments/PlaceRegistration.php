<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AssignmentSource;
use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\Seats;

/**
 * Staff place a registration into an option of a set by hand, or override its assignment
 * (modules-and-extensions.md, "Assignment sets": staff can place or override in every mode). The fact is
 * written confirmed, with the source `staff`, replacing whatever the registration held
 * in that set. Staff override: neither the option's capacity nor its eligibility is checked. The
 * registration row is locked first; the option must be one of the set's, and the set the registration's
 * event's.
 *
 * The placing is the registration's own act under the seat rule (`Registrations\Api\Seats`): when the
 * registration is `registered`, the seat in the option's pool is taken under a lock on the option row and may go
 * over the capacity. A registration that is not (waitlisted, withdrawn, removed) holds no seat, so the
 * assignment is written without one; the seat follows when it becomes `registered` (`Api\AssignmentSeats::retake()`).
 * The seat in the option the registration held in the set before is freed either way (never moved to anyone
 * else).
 */
final class PlaceRegistration implements AdminUseCase
{
    public function __construct(
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly Registrations $registrations = new Registrations(),
        private readonly PlacementLookup $lookup = new PlacementLookup(),
        private readonly Seats $seats = new Seats(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/place-registration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string'],
                'set_id' => ['type' => 'integer', 'minimum' => 1],
                'option' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
            ],
            'required' => ['event_id', 'code', 'set_id', 'option'],
            'additionalProperties' => false,
        ];
    }

    /** The per-event action a grant would carry (grants are S36's). */
    public function action(): string
    {
        return 'place-assignment';
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    public function execute(array $input, Context $context): null
    {
        $registration = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        if (!$this->registrations->lock($registration, $context)) {
            throw new NotFound();
        }
        $target = $this->lookup->find($registration, (int) $input['set_id'], (string) $input['option']);

        $key = ['registration' => $registration, 'setId' => $target->setId];
        $record = $this->assignments->findForUpdate($key, $context);
        if ($record !== null && $record->optionKey !== $target->option->key) {
            $held = new OptionSeatPool($registration->eventId, $target->setId, $record->optionKey);
            $this->seats->release($held, $registration, $context);
        }
        if ($this->registrations->find($registration)?->status === RegistrationStatus::Registered) {
            $this->seats->retake(
                new OptionSeatPool($registration->eventId, $target->setId, $target->option->key),
                $registration,
                SeatAct::Placement,
                $context
            );
        }
        $record ??= new AssignmentRecord(
            $registration,
            $target->setId,
            $target->option->key,
            AssignmentSource::Staff,
            AssignmentState::Confirmed,
        );
        $record->optionKey = $target->option->key;
        $record->source = AssignmentSource::Staff;
        $record->state = AssignmentState::Confirmed;
        $this->assignments->upsert($record, $context);

        return null;
    }
}
