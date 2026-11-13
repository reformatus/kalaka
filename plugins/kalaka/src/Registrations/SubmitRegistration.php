<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Forms\Api\Form;
use Kalaka\Forms\Api\FormPlacement;
use Kalaka\Forms\Api\FormRefused;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\PublicUseCase;
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
 * Submits a form of the registration slot: registrations-and-identity.md, "Status, capacity and
 * waitlist", "Reference codes", "Submissions and values".
 *
 * The answers were checked before the transaction (`Forms::validate()` reads WordPress), against the
 * event as read then; they come in through the constructor, the input names only the form and the
 * token. Inside, in this order: the event row `FOR UPDATE` (the first read, the lock order's first
 * level); a submission with the same token and the same answers is a repeat that writes nothing and
 * names the registration only to the after-submit content (N13), the same token with other answers is
 * `SubmitTokenReused` (N6); the event must still be the version the answers were checked against;
 * the form's requirements at this moment; then the capacity check, the reference code and the rows.
 */
final class SubmitRegistration implements PublicUseCase
{
    /**
     * @param int $eventVersion the version of the event the answers were checked against
     * @param list<string> $roles the current user's roles
     * @param array<string, string> $fieldTypes field key → the field block's name, of the form's definition: a
     *     stored value's key must be a participant field of the event with that type
     */
    public function __construct(
        private readonly Form $form,
        private readonly int $formVersion,
        private readonly ValidatedAnswers $answers,
        private readonly int $eventVersion,
        private readonly array $roles,
        private readonly bool $passwordGiven,
        private readonly array $fieldTypes = [],
        private readonly ReferenceCodeGenerator $codes = new ReferenceCodeGenerator(),
        private readonly EventCapacity $capacity = new EventCapacity(),
        private readonly Forms $forms = new Forms(),
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly FieldValueRepository $values = new FieldValueRepository(),
        private readonly RegistrationCount $count = new RegistrationCount(),
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

        $fingerprint = self::fingerprint($this->form->id, $this->answers->stored());
        $earlier = $this->submissions->withToken($eventId, $token);
        if ($earlier !== null) {
            // N6: a repeat must carry the same answers; its answer names the registration to nothing but the
            // after-submit content (N13).
            if ($earlier->answersHash === null || !hash_equals($earlier->answersHash, $fingerprint)) {
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

        $refusals = $this->forms->refusals($this->form, $event, $context->now(), $this->roles, $this->passwordGiven);
        if ($refusals !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- enum cases, no input
            throw new FormRefused($refusals);
        }

        $values = $this->declaredValues($eventId);

        if ($event->capacity === null) {
            $error = new SystemFailure(null, new \LogicException('A published event without a capacity.'));
            throw $error;
        }
        $status = RegistrationStatus::Registered;
        if ($event->isFull) {
            $status = RegistrationStatus::Waitlisted;
        } elseif ($this->count->registered($eventId) >= $event->capacity) {
            // The first counted registration that does not fit sets the latch, before any lower lock.
            $this->capacity->markFull($context, $eventId);
            $status = RegistrationStatus::Waitlisted;
        }

        $now = $context->now();
        $key = new RegistrationKey($eventId, $this->codes->generate($eventId));
        $this->registrations->upsert(new Registration(
            $key,
            $status,
            SecretLink::generate(),
            $now,
            $status === RegistrationStatus::Registered ? $now : null,
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

        return SubmitResult::created($key, $status);
    }

    /**
     * The stored answers, checked against the event's participant fields (#169), before anything is written:
     * every key must be an active participant field of the event, of the type the form gives it, and never
     * a `system` field or one a form may not write (`formWritable`), which no submit writes. Anything else
     * means the form and the event's list disagree (a declaration that has not caught up): a
     * `SystemFailure`, never a partial write. [UNAPPROVED N17]
     *
     * @return array<string, string> field key → the value to store
     * @throws SystemFailure
     */
    private function declaredValues(int $eventId): array
    {
        $declared = [];
        foreach ($this->forms->participantFields($eventId) as $field) {
            $declared[$field->key] = $field;
        }
        foreach (array_keys($this->answers->stored()) as $fieldKey) {
            $field = $declared[$fieldKey] ?? null;
            $type = $this->fieldTypes[$fieldKey] ?? null;
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

        return $this->answers->stored();
    }

    /**
     * The fingerprint of a submit's answers (N6): SHA-256 of the form ID and the stored answers, sorted by
     * key, as JSON. Two submits with the same answers have the same fingerprint whatever their order.
     *
     * @param array<string, string> $stored
     */
    public static function fingerprint(int $formId, array $stored): string
    {
        ksort($stored, SORT_STRING);
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
}
