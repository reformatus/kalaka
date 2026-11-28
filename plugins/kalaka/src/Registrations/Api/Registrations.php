<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\RegistrationList;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\Submission\SubmissionRepository;

/**
 * What other modules may do with a registration: read it (the row, its first submission's form and version,
 * its field values, its facts, an event's open ones), or lock it. Never the secret.
 */
final class Registrations
{
    public function __construct(
        private readonly RegistrationRepository $repository = new RegistrationRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
        private readonly RegistrationList $list = new RegistrationList(),
        private readonly Forms $forms = new Forms(),
    ) {
    }

    /** A plain read, without a lock; null when there is no such registration. */
    public function find(RegistrationKey $key): ?RegistrationRead
    {
        $registration = $this->repository->find($key);

        return $registration === null
            ? null
            : new RegistrationRead(
                $registration->key,
                $registration->status,
                $registration->registeredAt,
                $registration->ownerUserId,
            );
    }

    /**
     * The form and the form version of the registration's first submission (the registration slot's form, as
     * it was submitted); null when it has none. A plain read.
     *
     * @return array{form_id: int, form_version: int}|null
     */
    public function firstSubmission(RegistrationKey $key): ?array
    {
        $first = $this->submissions->find(['eventId' => $key->eventId, 'code' => $key->code, 'number' => 1]);

        return $first === null ? null : ['form_id' => $first->formId, 'form_version' => $first->formVersion];
    }

    /**
     * The registration's current field values, as the field types stored them. A plain read.
     *
     * @return array<string, string> field key → the stored value
     */
    public function values(RegistrationKey $key): array
    {
        $values = [];
        foreach ($this->values->forRegistration($key) as $value) {
            /** @var FieldValue $value */
            $values[$value->fieldKey] = $value->value;
        }

        return $values;
    }

    /**
     * The registration's fact source, from plain reads without a lock (inside the evaluation the run already
     * holds the row): the row, its field values (`values()`, one statement) typed over the event's participant
     * fields (`Forms::participantFacts()`: one statement and the block registry, no post). Null when there is
     * no such registration. `$now` is the moment of the act that reads (`time.now`): the caller's one clock.
     */
    public function factsOf(RegistrationKey $key, Event $event, \DateTimeImmutable $now): ?RegistrationFacts
    {
        $registration = $this->repository->find($key);
        if ($registration === null) {
            return null;
        }

        $fields = $this->forms->participantFacts($key->eventId, $this->values($key));

        return new RegistrationFacts(
            $key,
            $registration->status,
            $registration->registeredAt,
            $event,
            $now,
            $fields->values,
            [],
            $fields->kinds,
        );
    }

    /**
     * The status of each of the event's registrations, by code: one statement.
     *
     * @return array<string, RegistrationStatus>
     */
    public function statusesOf(int $eventId): array
    {
        $statuses = [];
        foreach ($this->list->forEvent($eventId) as $row) {
            $statuses[$row['code']] = $row['status'];
        }

        return $statuses;
    }

    /**
     * The event's `registered` and `waitlisted` registrations, in the list's order: the ones a money settings
     * apply re-evaluates (withdrawn and removed are left out). One statement.
     *
     * @return list<RegistrationKey>
     */
    public function openOf(int $eventId): array
    {
        $keys = [];
        foreach ($this->list->forEvent($eventId) as $row) {
            $status = $row['status'];
            if ($status === RegistrationStatus::Registered || $status === RegistrationStatus::Waitlisted) {
                $keys[] = new RegistrationKey($eventId, $row['code']);
            }
        }

        return $keys;
    }

    /**
     * Whether an open (`registered` or `waitlisted`) registration of the event holds the value in the field: two
     * statements however many registrations there are. A plain read.
     */
    public function openHolds(int $eventId, string $fieldKey, string $value): bool
    {
        $rows = $this->values->withValue($eventId, $fieldKey, $value);
        if ($rows === []) {
            return false;
        }
        $open = [];
        foreach ($this->openOf($eventId) as $key) {
            $open[$key->code] = true;
        }
        foreach ($rows as $row) {
            if (isset($open[$row->registration->code])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuses removing a chosen item: `ItemChosen` when an open registration of the event holds the value in the
     * field. Staff and settings saves that remove a tier, an option or an offer call it, so one refusal and one
     * code reach every surface. A null value means "any value held": CF4 (#245) answers it with `openChose()`;
     * until then no caller passes null, and on this branch a null matches nothing (it is read as value ''). A
     * plain read.
     */
    public function refuseRemovingChosen(int $eventId, string $fieldKey, ?string $value): void
    {
        // CF4 adds `openChose` for a null value (a charge's field chosen at all); until then `openHolds` alone.
        if ($this->openHolds($eventId, $fieldKey, (string) $value)) {
            throw new ItemChosen();
        }
    }

    /**
     * Reads the registration `FOR UPDATE` at `LockLevel::Registration`, so the run holds it
     * (`Context::holdsRegistration()`).
     *
     * @return bool false when there is no such registration
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure when the lock order forbids the lock now
     */
    public function lock(RegistrationKey $key, Context $context): bool
    {
        return $this->repository->findForUpdate($key, $context) !== null;
    }
}
