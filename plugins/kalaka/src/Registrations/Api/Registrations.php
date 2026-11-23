<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Events\Api\Event;
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
     * The registration's fact source, from a plain read without a lock (inside the evaluation the run already
     * holds the row). Null when there is no such registration.
     */
    public function factsOf(RegistrationKey $key, Event $event): ?RegistrationFacts
    {
        $registration = $this->repository->find($key);
        if ($registration === null) {
            return null;
        }

        return new RegistrationFacts(
            $key,
            $registration->status,
            $registration->registeredAt,
            $event,
            $this->requests->awaits($key, $registration->status),
        );
    }

    /**
     * Writes one field value from an extension's own use case (events-and-forms.md, "Fields": the source
     * "extension"; foundation.md: a field value is a fact whatever writes it), e.g. the answer to a request.
     * Inside a use case's run, with the registration locked by it; the value is the field type's stored form.
     * The field must be one the event declares, and not a "system" field, which stores nothing (foundation.md,
     * "Facts, decisions and computed values"). The caller owns the field's type: this does not check it.
     * [UNAPPROVED N11]
     *
     * @throws SystemFailure when the run does not hold the registration, the event has no such active field, or
     *                       the field is a "system" one
     */
    public function writeValue(RegistrationKey $key, string $fieldKey, string $value, Context $context): void
    {
        if (!$context->holdsRegistration($key)) {
            $error = new SystemFailure(null, new \LogicException('The run does not hold the registration.'));
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
