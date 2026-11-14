<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\RecipientUnavailable;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SecretLinks;

/**
 * A mail's value context: the recipient, the registration and its event (events-and-forms.md, "Values and
 * conditions"), from current data. The event's keys are `EventValues::of()`, as in a form. The
 * registration's fields are the event's participant fields: a key that only another form of the event holds
 * reads as none. The recipient comes from the marked fields of
 * the slot form (the form of the first submission). It reads WordPress posts, so it runs outside any use case.
 * // [UNAPPROVED N14] the keys a mail may read.
 */
final class MailValues
{
    public const RECIPIENT_EMAIL = 'recipient.email';
    public const RECIPIENT_NAME = 'recipient.name';
    public const CODE = 'registration.code';
    public const STATUS = 'registration.status';
    public const LINK = 'registration.link';

    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly SecretLinks $links = new SecretLinks(),
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * The keys and types a mail of the event may read: the context's keys, as `context()` declares them. A
     * body is checked against them at save (`BodyCheck`) and before the claim (`MailRenderer::check()`).
     *
     * @return array<string, ValueType>
     */
    public function types(int $eventId): array
    {
        return [
            ...array_map(
                static fn (Value $value): ValueType => $value->type,
                $this->forms->participantValues($eventId, [])
            ),
            ...EventValues::TYPES,
            self::RECIPIENT_EMAIL => ValueType::Text,
            self::RECIPIENT_NAME => ValueType::Text,
            self::CODE => ValueType::Text,
            self::STATUS => ValueType::Choice,
            self::LINK => ValueType::Text,
        ];
    }

    /**
     * The registration's contact email; null when its contact-email field has no answer. The sender asks for
     * it before the claim, so a data problem is a `RecipientUnavailable`, never another exception.
     *
     * @throws RecipientUnavailable no registration or first submission (`no_registration`), or a slot form
     *         that is unpublished now or whose submitted version has a problem (`no_recipient_form`)
     */
    public function recipient(RegistrationKey $key): ?string
    {
        $record = $this->read($key);
        if ($record === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a machine code, never output
            throw new RecipientUnavailable(RecipientUnavailable::NO_REGISTRATION);
        }
        try {
            $definition = $this->definition($record);
        } catch (SystemFailure) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a machine code, never output
            throw new RecipientUnavailable(RecipientUnavailable::NO_RECIPIENT_FORM);
        }
        $field = $this->marked($definition, true);
        $email = $field === null ? null : ($record['values'][$field] ?? null);

        return $email === null || $email === '' ? null : $email;
    }

    /**
     * @throws SystemFailure no such registration (`no_registration`) or event (`no_event`), a slot form that
     *         is not usable now, or a field of a type no longer contributed
     */
    public function context(RegistrationKey $key): ValueContext
    {
        $record = $this->read($key);
        $link = $this->links->url($key, home_url('/'));
        if ($record === null || $link === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('no_registration ' . $key->eventId . ' ' . $key->code);
        }
        $event = $this->events->find($key->eventId);
        if ($event === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and an ID, never output
            throw new SystemFailure('no_event ' . $key->eventId);
        }
        $definition = $this->definition($record);
        $email = $this->marked($definition, true);
        $name = $this->marked($definition, false);

        return new ValueContext([
            ...$this->forms->participantValues($key->eventId, $record['values']),
            ...EventValues::of($event),
            self::RECIPIENT_EMAIL => self::text($email === null ? '' : ($record['values'][$email] ?? '')),
            self::RECIPIENT_NAME => self::text($name === null ? '' : ($record['values'][$name] ?? '')),
            self::CODE => self::text($key->code),
            self::STATUS => Value::choice([$record['status']->value], $record['status']->word()),
            self::LINK => self::text($link),
        ]);
    }

    /**
     * The registration through `Registrations`: its status, the form of its first submission and its field
     * values; null when there is no such registration or it has no first submission.
     *
     * @return array{
     *     status: RegistrationStatus,
     *     form_id: int,
     *     form_version: int,
     *     values: array<string, string>,
     * }|null
     */
    private function read(RegistrationKey $key): ?array
    {
        $registration = $this->registrations->find($key);
        $first = $this->registrations->firstSubmission($key);
        if ($registration === null || $first === null) {
            return null;
        }

        return ['status' => $registration->status, ...$first, 'values' => $this->registrations->values($key)];
    }

    /**
     * The definition of the slot form's version the registration was submitted with: its marked fields are
     * the ones the answers were given in, and a later save that breaks the current version does not lose the
     * recipient.
     *
     * @param array{form_id: int, form_version: int} $record
     * @throws SystemFailure when the slot form is not usable now (`no_form`), or the version is not one of its
     *         revisions or its definition has a problem
     */
    private function definition(array $record): FormDefinition
    {
        $form = $this->forms->form($record['form_id']);
        if ($form === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('no_form ' . $record['form_id']);
        }

        return $this->forms->definition($form, $record['form_version']);
    }

    /** The key of the contact-email field (or the display-name field); null when the form has none. */
    private function marked(FormDefinition $definition, bool $email): ?string
    {
        foreach ($definition->fields as $field) {
            if ($email ? $field->isContactEmail() : $field->isDisplayName()) {
                return $field->key;
            }
        }

        return null;
    }

    private static function text(string $text): Value
    {
        return $text === '' ? Value::none(ValueType::Text) : Value::text($text, $text);
    }
}
