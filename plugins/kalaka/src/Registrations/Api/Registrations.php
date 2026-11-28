<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Forms\Api\Forms;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
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
        private readonly Requests $requests = new Requests(),
        private readonly ParticipantFields $participantFields = new ParticipantFields(),
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
            $this->requests->awaits($key, $registration->status),
            $this->requests->lastAnsweredAt($key),
        );
    }

    /**
     * Writes one field value from the extension that declared the field (events-and-forms.md, "Fields": the source
     * "extension"; foundation.md: a field value is a fact whatever writes it), e.g. the answer to a request.
     * Inside a use case's run, with the registration locked by it; the value is the field type's stored form.
     * `$declarer` is the writing module's slug, the one it declared the field with (`kalaka-aid`, `kalaka-support`):
     * one writer rule, a field is written by its declarer's own code only. The field must be one the event
     * declares, and not a "system" field, which stores nothing (foundation.md, "Facts, decisions and computed
     * values"). The caller owns the field's type: this does not check it. [UNAPPROVED N11] [UNAPPROVED N137]
     *
     * TODO(CF3, #231): refuse a field whose `declared_by` is another declarer. The column and
     * `ParticipantField::$declaredBy` arrive with CF3; until then the declarer is required but not compared, so
     * this completes at the restack onto CF3.
     *
     * @throws SystemFailure when the run does not hold the registration, the declarer is empty, the event has no
     *                       such active field, or the field is a "system" one
     */
    public function writeValue(
        RegistrationKey $key,
        string $fieldKey,
        string $value,
        Context $context,
        string $declarer,
    ): void {
        if (!$context->holdsRegistration($key)) {
            $error = new SystemFailure(null, new \LogicException('The run does not hold the registration.'));
            throw $error;
        }
        if ($declarer === '') {
            $error = new SystemFailure(null, new \LogicException('A field value is written for a declarer.'));
            throw $error;
        }
        $declared = null;
        foreach ($this->participantFields->of($key->eventId) as $field) {
            if ($field->key === $fieldKey) {
                $declared = $field;
                break;
            }
        }
        if ($declared === null) {
            $error = new SystemFailure(null, new \LogicException('The event declares no such participant field.'));
            throw $error;
        }
        if ($declared->source === ParticipantField::SOURCE_SYSTEM) {
            $error = new SystemFailure(null, new \LogicException('A system field stores no value.'));
            throw $error;
        }
        $stored = $this->values->findForUpdate(
            ['eventId' => $key->eventId, 'code' => $key->code, 'fieldKey' => $fieldKey],
            $context
        );
        if ($stored === null) {
            $this->values->upsert(new FieldValue($key, $fieldKey, $value), $context);

            return;
        }
        /** @var FieldValue $stored */
        $stored->value = $value;
        $this->values->upsert($stored, $context);
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
     * The statuses of some of an event's registrations, in one statement and without a lock; a code that is no
     * registration of the event is absent from the result. A plain read.
     *
     * @param list<string> $codes
     * @return array<string, RegistrationStatus> code → status
     */
    public function statusesOf(int $eventId, array $codes): array
    {
        return $this->list->statusesOf($eventId, $codes);
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
     * field, or, with no value, has chosen the field at all (a positive number: a charge's consent or quantity,
     * `openChose()`). Staff and settings saves that remove a tier, an option, an offer or a charge call it, so one
     * refusal and one code reach every surface. A plain read.
     */
    public function refuseRemovingChosen(int $eventId, string $fieldKey, ?string $value): void
    {
        $chosen = $value === null
            ? $this->openChose($eventId, $fieldKey)
            : $this->openHolds($eventId, $fieldKey, $value);
        if ($chosen) {
            throw new ItemChosen();
        }
    }

    /**
     * Whether an open (`registered` or `waitlisted`) registration of the event chose the field: holds a positive
     * number in it, which is a consent's `1` and a quantity of one or more (an unticked consent is `0`). The read
     * for a charge, whose "yes" is any positive value where `openHolds()` names one value; two statements however
     * many registrations there are. A plain read. [UNAPPROVED N307]
     */
    public function openChose(int $eventId, string $fieldKey): bool
    {
        $rows = $this->values->ofField($eventId, $fieldKey);
        $chosen = [];
        foreach ($rows as $row) {
            if (is_numeric($row->value) && (float) $row->value > 0) {
                $chosen[$row->registration->code] = true;
            }
        }
        if ($chosen === []) {
            return false;
        }
        foreach ($this->openOf($eventId) as $key) {
            if (isset($chosen[$key->code])) {
                return true;
            }
        }

        return false;
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
