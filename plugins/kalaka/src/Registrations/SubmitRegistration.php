<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Actor\AccountActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\ParticipantFields\Api\ParticipantField;
use Kalaka\Registrations\Account\AccountOutcome;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatAct;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\SeatsFull;
use Kalaka\Registrations\Api\SubmitResult;
use Kalaka\Registrations\Api\SubmitToken;
use Kalaka\Registrations\Api\SubmitTokenReused;
use Kalaka\Registrations\Draft\DraftRepository;
use Kalaka\Registrations\FieldValue\FieldValue;
use Kalaka\Registrations\FieldValue\FieldValueRepository;
use Kalaka\Registrations\Submission\Submission;
use Kalaka\Registrations\Submission\SubmissionRepository;

/**
 * Submits a form of the registration slot: registrations-and-identity.md, "Status, capacity and
 * waitlist", "Reference codes", "Submissions and values".
 *
 * The answers were checked before the transaction (`Forms::validate()` reads WordPress), against the
 * event as read then, and the event's participant fields were read then too (`ParticipantFields::of()`,
 * a read outside the transaction); both come in through the constructor, the input names only the form and the
 * token. Inside, in this order: the event row `FOR UPDATE` (the first read, the lock order's first
 * level); a submission with the same token and the same answers is a repeat that writes nothing and
 * names the registration only to the after-submit content (N13), the same token with other answers is
 * `SubmitTokenReused` (N6); the event must still be the version the answers were checked against;
 * the form's requirements at this moment; then the capacity check, the reference code and the rows, and
 * last the action mail of the resulting status (`registered` or `waitlisted`), queued in the same run with
 * the registration as its cause (`ActionMails::due()`; a mail switched off queues nothing), and the
 * person's draft of the form is deleted (S74).
 *
 * Accounts (S15): a guest's submit to a `mandatory` event is one of the form's refusals, checked here under
 * the lock with the others (`account_required`). The owner account was decided before the transaction
 * (`Account\AccountOutcome`, through the constructor). Only a new registration takes it: its row gets the
 * owner. A repeat writes nothing. An unverified account's set-password link travels in the status mail itself
 * (`account.password_link`).
 */
final class SubmitRegistration implements PublicUseCase
{
    /**
     * @param int $eventVersion the version of the event the answers were checked against
     * @param list<string> $roles the current user's roles
     * @param array<string, string> $fieldTypes field key → the field block's name, of the form's definition: a
     *     stored value's key must be a participant field of the event with that type
     * @param list<ParticipantField> $participantFields the event's participant fields, read before the transaction
     * @param AccountOutcome|null $account the owner account; null for none
     * @param \Throwable|null $walkFailure the failure of the answers' walk (`InvalidAnswers`, `FormTerminal`), kept
     *     until the token's repeat rule has been applied under the lock, then thrown
     * @param string|null $fingerprint of the answers as sent (`fingerprint()`); null (not given) never matches an
     *     earlier submit's, so a repeat is refused
     * @param list<SeatClaim> $seatClaims the limited choices of the answers, each a pool whose seat a
     *     `registered` submit takes (a waitlisted one may claim no limited pool)
     */
    public function __construct(
        private readonly Form $form,
        private readonly int $formVersion,
        private readonly ValidatedAnswers $answers,
        private readonly int $eventVersion,
        private readonly array $roles,
        private readonly bool $passwordGiven,
        private readonly array $fieldTypes = [],
        private readonly array $participantFields = [],
        private readonly ReferenceCodeGenerator $codes = new ReferenceCodeGenerator(),
        private readonly EventCapacity $capacity = new EventCapacity(),
        private readonly Forms $forms = new Forms(),
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
        private readonly Seats $seats = new Seats(),
        private readonly ActionMails $mails = new ActionMails(),
        private readonly ?AccountOutcome $account = null,
        private readonly ?\Throwable $walkFailure = null,
        private readonly ?string $fingerprint = null,
        private readonly ?string $draftClaimHash = null,
        private readonly DraftRepository $drafts = new DraftRepository(),
        private readonly array $seatClaims = [],
    ) {
    }

    public static function name(): string
    {
        return 'kalaka-portal/submit-registration';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form' => ['type' => 'integer', 'minimum' => 1],
                'token' => ['type' => 'string', 'pattern' => SubmitToken::PATTERN],
            ],
            'required' => ['form', 'token'],
            'additionalProperties' => false,
        ];
    }

    public function registrations(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): SubmitResult
    {
        if ((int) $input['form'] !== $this->form->id || $this->form->placement !== FormPlacement::Registration) {
            $error = new SystemFailure(
                null,
                new \LogicException('The submit names another form than it was built for, or one outside the slot.')
            );
            throw $error;
        }
        $token = (string) $input['token'];
        $eventId = $this->form->eventId;

        $event = $this->capacity->lockForRegistration($context, $eventId);

        $fingerprint = $this->fingerprint;
        $earlier = $this->submissions->withToken($eventId, $token);
        if ($earlier !== null) {
            // N6: a repeat must carry the same answers; its answer names the registration to nothing but the
            // after-submit content (N13).
            if (
                $fingerprint === null
                || $earlier->answersHash === null
                || !hash_equals($earlier->answersHash, $fingerprint)
            ) {
                $error = new SubmitTokenReused();
                throw $error;
            }
            $registration = $this->registrations->find($earlier->registration);
            if ($registration === null) {
                $error = new SystemFailure(null, new \LogicException('A submission without its registration.'));
                throw $error;
            }
            $context->touch(Subject::registration($registration->key));

            return SubmitResult::repeat($registration->key, $registration->status);
        }

        if ($event->version !== $this->eventVersion) {
            // The answers' conditions read the event as it was: the caller checks them again.
            $error = new VersionConflict();
            throw $error;
        }
        // The answers' walk failed before the transaction: said now, after the repeat rule had its say.
        if ($this->walkFailure !== null) {
            throw $this->walkFailure;
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

        $values = self::declared($this->answers->stored(), $this->fieldTypes, $this->participantFields);

        if ($event->capacity === null) {
            $error = new SystemFailure(null, new \LogicException('A published event without a capacity.'));
            throw $error;
        }
        // The one "goes to the waitlist" read, latch-aware, under the event lock: the form side reads the same.
        $goesToWaitlist = $this->seats->left($this->seats->eventPool($eventId)) === 0;
        $status = $goesToWaitlist ? RegistrationStatus::Waitlisted : RegistrationStatus::Registered;
        if ($goesToWaitlist) {
            $this->refuseLimitedClaims();
            if (!$event->isFull) {
                // The first counted registration that does not fit sets the latch, before any lower lock.
                $this->capacity->markFull($context, $eventId);
            }
        }

        // The pool rows come before the registration row in the lock order; a full pool refuses the submit.
        $claims = $status === RegistrationStatus::Registered ? $this->lockedClaims($context) : [];

        $now = $context->now();
        $key = new RegistrationKey($eventId, $this->codes->generate($eventId));
        $this->registrations->upsert(new Registration(
            $key,
            $status,
            SecretLink::generate(),
            $now,
            $status === RegistrationStatus::Registered ? $now : null,
            ownerUserId: $this->account?->ownerUserId,
        ), $context);
        $this->submissions->insert(new Submission(
            $key,
            1,
            $this->form->id,
            $this->formVersion,
            $this->answers->submitted(),
            $now,
            $token,
            $fingerprint,
        ), $context);
        foreach ($values as $fieldKey => $value) {
            $this->values->upsert(new FieldValue($key, $fieldKey, $value), $context);
        }
        foreach ($claims as $claim) {
            try {
                $this->seats->take($claim->pool, $key, SeatAct::Submit, $context);
            } catch (SeatsFull) {
                // A pool whose row sits after the registration's in the lock order: the run rolls back.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a field key and a fixed text
                throw new InvalidAnswers([$claim->fieldKey => SeatsFull::TEXT]);
            }
        }
        $this->mails->due(
            $context,
            $status === RegistrationStatus::Registered ? ActionMail::Registered : ActionMail::Waitlisted,
            $key,
            Subject::registration($key),
        );
        $this->deleteDrafts($context);

        return SubmitResult::created($key, $status);
    }

    /**
     * S74: the submit ends the person's draft of the form, in its transaction: a logged-in person's own,
     * and the guest draft of the form the request's cookie names (a guest's from a link or the quiz).
     */
    private function deleteDrafts(Context $context): void
    {
        // The guest draft first, then the person's own: the order every draft writer locks them in.
        if ($this->draftClaimHash !== null) {
            $guest = $this->drafts->ofClaim($this->draftClaimHash, $context);
            if ($guest !== null && $guest->isGuestOf($this->form->id)) {
                $this->drafts->delete($guest, $context);
            }
        }
        $actor = $context->actor();
        if ($actor instanceof AccountActor) {
            $own = $this->drafts->ofUser($this->form->eventId, $this->form->id, $actor->userId, $context);
            if ($own !== null) {
                $this->drafts->delete($own, $context);
            }
        }
    }

    /**
     * A submit that lands on the waitlist may choose nothing limited (owner ruling 2026-11-25, O4/A3): for it
     * everything limited acts as full, so any claim on a pool with a limit refuses the submit on that choice, with
     * the same refusal and text as a full pool (`SeatsFull::TEXT`), before anything is written.
     * Unlimited pools are not refused.
     *
     * @throws InvalidAnswers
     */
    private function refuseLimitedClaims(): void
    {
        $refused = [];
        foreach ($this->seatClaims as $claim) {
            if ($claim->pool->limit() !== null) {
                $refused[$claim->fieldKey] = SeatsFull::TEXT;
            }
        }
        if ($refused !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field keys and a fixed text
            throw new InvalidAnswers($refused);
        }
    }

    /**
     * Orders the submit's seat claims as their pool rows may be locked (`Seats::inLockOrder()`: by level, table
     * and key as the runner's lock guard compares them), locks the pool rows that sit before the registration row
     * in the lock order (quota rows), and refuses the submit when one of them is full: the form comes back with
     * the message on that choice and nothing is saved. The other pools are locked and checked by `Seats::take()`
     * once the registration row is in, in the same order.
     *
     * @return list<SeatClaim>
     * @throws InvalidAnswers
     */
    private function lockedClaims(Context $context): array
    {
        $claims = $this->seats->inLockOrder($this->seatClaims);
        foreach ($claims as $claim) {
            if ($claim->pool->lockLevel()->value >= LockLevel::Registration->value) {
                continue;
            }
            $left = $this->seats->lock($claim->pool, $context);
            if ($left !== null && $left < 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a field key and a fixed text
                throw new InvalidAnswers([$claim->fieldKey => SeatsFull::TEXT]);
            }
        }

        return $claims;
    }

    /**
     * The stored answers, checked against the event's participant fields (#169, as read before the
     * transaction), before anything is written:
     * every key must be an active participant field of the event, of the type the form gives it, and never
     * a `system` field or one a form may not write (`formWritable`), which no submit writes. Anything else
     * means the form and the event's list disagree (a declaration that has not caught up): a
     * `SystemFailure`, never a partial write.
     *
     * The additional form's submit (`SubmitAdditionalForm`) checks its answers the same way.
     *
     * @param array<string, string> $stored field key → the value to store
     * @param array<string, string> $fieldTypes field key → the field block's name, of the form's definition
     * @param list<ParticipantField> $participantFields
     * @return array<string, string> field key → the value to store
     * @throws SystemFailure
     */
    public static function declared(array $stored, array $fieldTypes, array $participantFields): array
    {
        $declared = [];
        foreach ($participantFields as $field) {
            $declared[$field->key] = $field;
        }
        foreach (array_keys($stored) as $fieldKey) {
            $fieldKey = (string) $fieldKey;
            $field = $declared[$fieldKey] ?? null;
            $type = $fieldTypes[$fieldKey] ?? null;
            if ($field === null || ($type !== null && $field->type !== $type)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure('undeclared_field_key ' . $fieldKey);
            }
            if ($field->source === 'system' || !$field->formWritable) {
                $problem = $field->source === 'system' ? 'system_field ' : 'extension_field ';
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
                throw new SystemFailure($problem . $fieldKey);
            }
        }

        return $stored;
    }

    /**
     * The fingerprint of a submit's answers (N6): SHA-256 of the form ID and the answers as sent, sorted by
     * key, as JSON. Two submits with the same answers have the same fingerprint whatever their order. It is
     * over what was sent, not what the walk kept, so a resend is a repeat even when a value the form's
     * conditions read (the places left) has changed since and the walk keeps other answers now (CF2).
     *
     * @param array<array-key, mixed> $sent
     */
    public static function fingerprint(int $formId, array $sent): string
    {
        $stored = self::sorted($sent);
        try {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() would repair invalid UTF-8
            $json = json_encode([$formId, $stored], $flags);
        } catch (\JsonException $e) {
            $error = new SystemFailure(null, $e);
            throw $error;
        }

        return hash('sha256', $json);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
