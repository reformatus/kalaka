<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Forms\Api\AccessRefusal;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\MoneyRelevantFieldType;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Api\SubmitTokenReused;
use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\Submission\Submission;
use Kalaka\Registrations\Submission\SubmissionRepository;

/**
 * Submits an additional form to an existing registration: events-and-forms.md, "Forms and the registration
 * slot" and "Changing participant fields after submit"; registrations-and-identity.md, "Submissions and
 * values". It never creates a registration and never touches capacity, the "full" latch, the status or
 * `registered_at`.
 *
 * As for a registration form, the answers were checked and the event's participant fields read before the
 * transaction. Inside, in this order: the event row `FOR UPDATE` (the lock order's first level); a repeat
 * (the same token with the same answers, for the same registration) writes nothing; the event must still be
 * the version the answers were checked against; the form's requirements at this moment (its open period
 * among them); then the registration row `FOR UPDATE`, which must be `registered` or `waitlisted`.
 *
 * Every submit appends a submission (the next number), and the latest write wins (owner B9). Only the
 * fields that showed are written (B8): an answered one stores its answer, and one left blank clears its
 * value when the form showed that value (a type that refuses prefill opened it empty, so its value stays). A
 * shown consent left unticked is not blank: it stores `0` (the answer no), so a consent given earlier is
 * overwritten with `0`, never cleared (the owner's ruling 2026-11-18, truthiness R3).
 * A money-relevant field's value, once written, is never overwritten here (B4: the organizer's unlock is
 * not built). Each written or cleared value is audited as `field:<key>`.
 */
final class SubmitAdditionalForm implements PortalUseCase
{
    /**
     * @param int $eventVersion the version of the event the answers were checked against
     * @param list<string> $roles the current user's roles
     * @param array<string, string> $fieldTypes field key → the field block's name, of the form's definition: a
     *     stored value's key must be a participant field of the event with that type
     * @param list<ParticipantField> $participantFields the event's participant fields, read before the transaction
     */
    public function __construct(
        private readonly Form $form,
        private readonly FormDefinition $definition,
        private readonly ValidatedAnswers $answers,
        private readonly int $eventVersion,
        private readonly array $roles,
        private readonly bool $passwordGiven,
        private readonly array $fieldTypes = [],
        private readonly array $participantFields = [],
        private readonly EventCapacity $events = new EventCapacity(),
        private readonly Forms $forms = new Forms(),
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/submit-additional-form';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form' => ['type' => 'integer', 'minimum' => 1],
                'token' => ['type' => 'string', 'pattern' => SubmitToken::PATTERN],
                'event' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string'],
            ],
            'required' => ['form', 'token', 'event', 'code'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [new RegistrationKey((int) $input['event'], (string) $input['code'])];
    }

    public function execute(array $input, Context $context): SubmitResult
    {
        $key = new RegistrationKey((int) $input['event'], (string) $input['code']);
        if (
            (int) $input['form'] !== $this->form->id
            || $this->form->placement !== FormPlacement::Additional
            || $key->eventId !== $this->form->eventId
        ) {
            $error = new SystemFailure(
                null,
                new \LogicException('The submit names another form or event than it was built for, or a slot form.')
            );
            throw $error;
        }
        $token = (string) $input['token'];

        $event = $this->events->lockForRegistration($context, $key->eventId);

        $fingerprint = SubmitRegistration::fingerprint($this->form->id, $this->answers->stored());
        $earlier = $this->submissions->withToken($key->eventId, $token);
        if ($earlier !== null) {
            if (
                $earlier->answersHash === null
                || !hash_equals($earlier->answersHash, $fingerprint)
                || $earlier->registration->code !== $key->code
            ) {
                $error = new SubmitTokenReused();
                throw $error;
            }
            $registration = $this->registrations->find($key) ?? throw new SystemFailure();

            return SubmitResult::supplemented($key, $registration->status, true);
        }

        if ($event->version !== $this->eventVersion) {
            $error = new VersionConflict();
            throw $error;
        }

        $refusals = $this->forms->refusals(
            $this->form,
            $event,
            $context->now(),
            $this->roles,
            $this->passwordGiven,
            $context->actor() instanceof AccountActor
        );
        if ($refusals !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- enum cases, no input
            throw new FormRefused($refusals);
        }

        $registration = $this->registrations->findForUpdate($key, $context);
        if ($registration === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an enum case, no input
            throw new FormRefused([AccessRefusal::NoActiveRegistration]);
        }
        if (
            $registration->status !== RegistrationStatus::Registered
            && $registration->status !== RegistrationStatus::Waitlisted
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an enum case, no input
            throw new FormRefused([AccessRefusal::RegistrationMayNotSubmit]);
        }

        $stored = SubmitRegistration::declared(
            $this->answers->stored(),
            $this->fieldTypes,
            $this->participantFields
        );
        $current = [];
        foreach ($this->values->forRegistration($key) as $value) {
            $current[$value->fieldKey] = $value;
        }
        $cleared = $this->cleared($current);
        $this->refuseLockedOverwrites($stored, $current);

        $this->submissions->insert(new Submission(
            $key,
            $this->submissions->lastNumber($key) + 1,
            $this->form->id,
            $this->definition->version,
            $this->answers->submitted(),
            $context->now(),
            $token,
            $fingerprint,
        ), $context);
        foreach ($stored as $fieldKey => $text) {
            $value = $current[$fieldKey] ?? null;
            if ($value === null) {
                $this->values->upsert(new FieldValue($key, $fieldKey, $text), $context);
            } elseif ($value->value !== $text) {
                $value->value = $text;
                $this->values->upsert($value, $context);
            }
        }
        foreach ($cleared as $value) {
            $this->values->delete($value, $context);
        }

        return SubmitResult::supplemented($key, $registration->status);
    }

    /**
     * The current values a shown field left blank clears: those the form showed, as its type prefills them.
     *
     * @param array<string, FieldValue> $current
     * @return list<FieldValue>
     */
    private function cleared(array $current): array
    {
        $stored = $this->answers->stored();
        $types = Contributions::current()->fieldTypes();
        $cleared = [];
        foreach ($this->answers->shown() as $fieldKey) {
            $value = $current[$fieldKey] ?? null;
            $field = $this->definition->field($fieldKey);
            if ($value === null || $field === null || array_key_exists($fieldKey, $stored)) {
                continue;
            }
            $type = $types[$field->blockName] ?? null;
            if ($type !== null && $type->prefill(new FieldTypeInput($value->value, $field->attributes)) !== null) {
                $cleared[] = $value;
            }
        }
        $keys = array_map(static fn (FieldValue $value): string => $value->fieldKey, $cleared);
        // A clear gets the guard a store gets (#189 C2-1): a field no form may write (or the event no
        // longer declares) is never cleared by a form, and the submit is refused as a store's would be.
        SubmitRegistration::declared(array_fill_keys($keys, ''), $this->fieldTypes, $this->participantFields);
        $this->refuseLockedOverwrites(array_fill_keys($keys, ''), $current);

        return $cleared;
    }

    /**
     * A money-relevant field keeps its first write (B4): a different value, or a clear, is refused.
     *
     * @param array<string, string> $written field key → the value to write ('' for a clear)
     * @param array<string, FieldValue> $current
     * @throws RuleViolation
     */
    private function refuseLockedOverwrites(array $written, array $current): void
    {
        $types = Contributions::current()->fieldTypes();
        foreach ($written as $fieldKey => $text) {
            $field = $this->definition->field((string) $fieldKey);
            $value = $current[$fieldKey] ?? null;
            if (
                $field !== null && $value !== null && $value->value !== $text
                && ($types[$field->blockName] ?? null) instanceof MoneyRelevantFieldType
            ) {
                $error = new RuleViolation();
                throw $error;
            }
        }
    }
}
