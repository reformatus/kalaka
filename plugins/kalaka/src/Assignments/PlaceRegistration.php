<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Api\AssignmentSource;
use Kalaka\Assignments\Api\AssignmentState;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * Staff place a registration into an option of a set by hand, or override its assignment
 * (modules-and-extensions.md, "Assignment sets": staff can place or override in every mode). The fact is
 * written confirmed, with the source `staff` ([UNAPPROVED N5]), replacing whatever the registration held in that set. Staff
 * override: neither the option's capacity nor its eligibility is checked. The registration row is locked
 * first; the option must be one of the set's, and the set the registration's event's.
 */
final class PlaceRegistration implements AdminUseCase
{
    public function __construct(
        private readonly SetRepository $sets = new SetRepository(),
        private readonly OptionRepository $options = new OptionRepository(),
        private readonly AssignmentRepository $assignments = new AssignmentRepository(),
        private readonly Registrations $registrations = new Registrations(),
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

    /** [UNAPPROVED N5] the per-event action a grant would carry (grants are S36's). */
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
        $set = $this->sets->find((int) $input['set_id']);
        if ($set === null || $set->eventId !== $registration->eventId) {
            throw new NotFound();
        }
        $optionKey = (string) $input['option'];
        if ($this->options->find(['setId' => $set->id, 'key' => $optionKey]) === null) {
            throw new InvalidInput();
        }

        $key = ['registration' => $registration, 'setId' => $set->id];
        $record = $this->assignments->findForUpdate($key, $context) ?? new AssignmentRecord(
            $registration,
            $set->id,
            $optionKey,
            AssignmentSource::Staff,
            AssignmentState::Confirmed,
        );
        $record->optionKey = $optionKey;
        $record->source = AssignmentSource::Staff;
        $record->state = AssignmentState::Confirmed;
        $this->assignments->upsert($record, $context);

        return null;
    }
}
