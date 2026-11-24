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
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PortalUseCase;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Events\Api\Event;
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
 * among them); then the registration row `FOR UPDATE`, which must be `registered` or `waitlisted`, and whose
 * values and status must be what the walk read (else `VersionConflict`: the submit is run again).
 *
 * An additional form takes no new seat (D7): if the registration's values as they would be after the submit make a
 * seat claim (`SeatClaimSource`) in a pool it does not hold, the submit is refused and nothing is written, so a
 * re-chosen tier or offer never changes a price with no seat taken. A request-mode set choice claims nothing at
 * submit, so re-choosing a request still works.
 *
 * Every submit appends a submission (the next number), and the latest write wins (owner B9). Only the
 * fields that showed are written (B8): an answered one stores its answer, and one left blank clears its
 * value when the form showed that value (a type that refuses prefill opened it empty, so its value stays). A
 * shown consent left unticked is not blank: it stores `0` (the answer no), so a consent given earlier is
 * overwritten with `0`, never cleared (the owner's ruling 2026-11-18, truthiness R3).
 * A field the participant field locks after its first write (`ParticipantFields::lockedAfterFirstWrite()`: money
 * relevant, declared so by its extension or by its type) is never overwritten here (B4: the organizer's unlock is
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
     * @param array<string, array<string, bool>> $clearable field key → a value it held before the transaction →
     *     whether the field's type opens it with that value (`clearable()`), so no field type runs inside it
     * @param array{values: array<string, string>, facts: array<string, string>}|null $read the registration's
     *     field values and facts the walk read (its conditions may read them, `RegistrationFacts::formRead()`):
     *     checked again under the registration lock, and a difference runs the submit again (`VersionConflict`)
     * @param string|null $fingerprint of the answers as sent (`SubmitRegistration::fingerprint()`); null (not
     *     given) never matches an earlier submit's, so a repeat is refused
     * @param list<SeatClaim> $newClaims the claims the submit would make that the registration's values now do not
     *     (`newClaims()`, asked before the transaction): refused unless the registration holds the pool
     * @param \Throwable|null $walkFailure the failure of the answers' walk (`InvalidAnswers`, `FormTerminal`), kept
     *     until the token's repeat rule has been applied under the lock, then thrown
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
        private readonly array $clearable = [],
        private readonly ?array $read = null,
        private readonly ?\Throwable $walkFailure = null,
        private readonly EventCapacity $events = new EventCapacity(),
        private readonly Forms $forms = new Forms(),
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
        private readonly Registrations $facts = new Registrations(),
        private readonly Seats $seats = new Seats(),
        private readonly ParticipantFields $fieldRules = new ParticipantFields(),
        private readonly array $newClaims = [],
        private readonly ?string $fingerprint = null,
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

        $fingerprint = $this->fingerprint;
        $earlier = $this->submissions->withToken($key->eventId, $token);
        if ($earlier !== null) {
            if (
                $fingerprint === null
                || $earlier->answersHash === null
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
        $this->refuseChangedRead($key, $event, $current, $context->now());
        // The answers' walk failed before the transaction: said now, after the repeat rule, the event version
        // and the re-read of what the walk read (a failure on facts that moved is run again, not said).
        if ($this->walkFailure !== null) {
            throw $this->walkFailure;
        }
        $cleared = $this->cleared($current);
        $this->refuseLockedOverwrites($stored, $current);
        $this->refuseNewSeats($key);

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
     * The values and the facts the walk read must still be what the locked registration holds (CF2, D9): a staff
     * write between the walk and the lock may change what the form's conditions showed, so the submit is run
     * again and the walk reads them anew. The facts are the one builder's (`RegistrationFacts::formRead()`, as the
     * mails read them), compared with the stored field values as they are, not by a version marker. A pool's
     * places left are no part of the read: a seat count moving is not a changed read.
     *
     * @param array<string, FieldValue> $current the registration's values by field key, read under the lock
     * @throws VersionConflict
     */
    private function refuseChangedRead(
        RegistrationKey $key,
        Event $event,
        array $current,
        \DateTimeImmutable $now
    ): void
    {
        if ($this->read === null) {
            return;
        }
        $now = [];
        foreach ($current as $fieldKey => $value) {
            $now[$fieldKey] = $value->value;
        }
        $before = $this->read['values'];
        ksort($now);
        ksort($before);
        $facts = $this->facts->factsOf($key, $event, $now)?->formRead() ?? [];
        if ($this->read['facts'] !== $facts || $before !== $now) {
            $error = new VersionConflict();
            throw $error;
        }
    }

    /**
     * An additional form takes no new seat (D7), asked before the transaction (a seat claim source is an outside
     * point): the claims the registration's values after this submit would make (the stored ones, the blanks the
     * form clears taken out, the written answers over them) that its values now do not make already. A waitlisted
     * registration holds no seat, so a choice it already made is no new claim; only a changed one is. A
     * request-mode set choice makes no claim at submit. The use case refuses those whose pool the registration
     * does not hold. [UNAPPROVED N304]
     *
     * @param list<SeatClaimSource> $sources
     * @param array<string, FieldValue> $current the registration's values by field key, as read before the
     *     transaction (checked again under the lock)
     * @param array<string, array<string, bool>> $clearable `clearable()`
     * @return list<SeatClaim>
     */
    public static function newClaims(
        array $sources,
        Event $event,
        ValidatedAnswers $answers,
        array $current,
        array $clearable
    ): array {
        if ($sources === []) {
            return [];
        }
        $stored = $answers->stored();
        $before = [];
        foreach ($current as $fieldKey => $value) {
            $before[$fieldKey] = $value->value;
        }
        $after = $before;
        foreach ($answers->shown() as $fieldKey) {
            $blanked = isset($before[$fieldKey]) && !array_key_exists($fieldKey, $stored);
            if ($blanked && ($clearable[$fieldKey][$before[$fieldKey]] ?? false)) {
                unset($after[$fieldKey]);
            }
        }
        $after = $stored + $after;
        $held = [];
        foreach (self::claimsOf($sources, $event, $before) as $claim) {
            $held[self::poolId($claim)] = true;
        }
        $new = [];
        foreach (self::claimsOf($sources, $event, $after) as $claim) {
            if (!isset($held[self::poolId($claim)])) {
                $new[] = $claim;
            }
        }

        return $new;
    }

    /**
     * A new claim in a pool the registration does not hold refuses the submit with nothing written (D7).
     *
     * @throws RuleViolation
     */
    private function refuseNewSeats(RegistrationKey $key): void
    {
        foreach ($this->newClaims as $claim) {
            if (!$this->seats->holds($claim->pool, $key)) {
                $error = new RuleViolation();
                throw $error;
            }
        }
    }

    /**
     * @param list<SeatClaimSource> $sources
     * @param array<string, string> $values field key → value
     * @return list<SeatClaim>
     */
    private static function claimsOf(array $sources, Event $event, array $values): array
    {
        $answers = new ValidatedAnswers($values, $values);
        $claims = [];
        foreach ($sources as $source) {
            array_push($claims, ...$source->claims($event, $answers));
        }

        return $claims;
    }

    private static function poolId(SeatClaim $claim): string
    {
        return $claim->pool->eventId() . '/' . $claim->pool->key();
    }

    /**
     * What `cleared()` needs from the field types, asked before the transaction (a field type is an outside
     * point: events-and-forms.md, "Forms implementation"): for each field the form showed and the answers left
     * blank, whether the type opens its current value (`prefill()` is not null).
     *
     * @param array<string, FieldValue> $current the registration's values by field key
     * @return array<string, array<string, bool>> field key → the value → whether the type opens with it
     */
    public static function clearable(FormDefinition $definition, ValidatedAnswers $answers, array $current): array
    {
        $stored = $answers->stored();
        $types = Contributions::current()->fieldTypes();
        $clearable = [];
        foreach ($answers->shown() as $fieldKey) {
            $value = $current[$fieldKey] ?? null;
            $field = $definition->field($fieldKey);
            if ($value === null || $field === null || array_key_exists($fieldKey, $stored)) {
                continue;
            }
            $type = $types[$field->blockName] ?? null;
            $clearable[$fieldKey][$value->value] = $type !== null
                && $type->prefill(new FieldTypeInput($value->value, $field->attributes)) !== null;
        }

        return $clearable;
    }

    /**
     * The current values a shown field left blank clears: those the form showed, as its type prefills them
     * (answered before the transaction). A value that is not the one asked about changed meanwhile: the
     * submit is run again.
     *
     * @param array<string, FieldValue> $current
     * @return list<FieldValue>
     * @throws VersionConflict
     */
    private function cleared(array $current): array
    {
        $stored = $this->answers->stored();
        $cleared = [];
        foreach ($this->answers->shown() as $fieldKey) {
            $value = $current[$fieldKey] ?? null;
            $field = $this->definition->field($fieldKey);
            if ($value === null || $field === null || array_key_exists($fieldKey, $stored)) {
                continue;
            }
            $opens = $this->clearable[$fieldKey][$value->value] ?? null;
            if ($opens === null) {
                $error = new VersionConflict();
                throw $error;
            }
            if ($opens) {
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
     * A field locked after its first write keeps it (B4, D5): a different value, or a clear, is refused. The lock is
     * the participant field's (`ParticipantFields::lockedAfterFirstWrite()`: the flag its declaration sets, or its
     * type's own capability), not the form block's. [UNAPPROVED N303]
     *
     * @param array<string, string> $written field key → the value to write ('' for a clear)
     * @param array<string, FieldValue> $current
     * @throws RuleViolation
     */
    private function refuseLockedOverwrites(array $written, array $current): void
    {
        $fields = [];
        foreach ($this->participantFields as $field) {
            $fields[$field->key] = $field;
        }
        foreach ($written as $fieldKey => $text) {
            $field = $fields[(string) $fieldKey] ?? null;
            $value = $current[$fieldKey] ?? null;
            if (
                $field !== null && $value !== null && $value->value !== $text
                && $this->fieldRules->lockedAfterFirstWrite($field)
            ) {
                $error = new RuleViolation();
                throw $error;
            }
        }
    }
}
