<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\RecipientUnavailable;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationRead;
use Kalaka\Registrations\Api\RegistrationRecord;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * A mail's value context: the recipient and the registration (events-and-forms.md, "Values and
 * conditions"), from current data. The registration's fields are the event's participant fields: a key
 * that only another form of the event holds reads as none. The recipient comes from the marked fields of
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
        private readonly RegistrationRead $registrations = new RegistrationRead(),
        private readonly Forms $forms = new Forms(),
    ) {
    }

    /**
     * The registration's contact email; null when its contact-email field has no answer. The sender asks for
     * it before the claim, so a data problem is a `RecipientUnavailable`, never another exception.
     *
     * @throws RecipientUnavailable no registration or first submission (`no_registration`), or a slot form
     *         that is unpublished or has a problem now (`no_recipient_form`)
     */
    public function recipient(RegistrationKey $key): ?string
    {
        $record = $this->registrations->find($key);
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
        $email = $field === null ? null : ($record->values[$field] ?? null);

        return $email === null || $email === '' ? null : $email;
    }

    /**
     * @throws SystemFailure no such registration (`no_registration`), a slot form that is not usable now, or
     *         a field of a type no longer contributed
     */
    public function context(RegistrationKey $key): ValueContext
    {
        $record = $this->registrations->find($key);
        $link = $this->registrations->linkUrl($key);
        if ($record === null || $link === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('no_registration ' . $key->eventId . ' ' . $key->code);
        }
        $definition = $this->definition($record);
        $email = $this->marked($definition, true);
        $name = $this->marked($definition, false);

        return new ValueContext([
            ...$this->forms->participantValues($key->eventId, $record->values),
            self::RECIPIENT_EMAIL => self::text($email === null ? '' : ($record->values[$email] ?? '')),
            self::RECIPIENT_NAME => self::text($name === null ? '' : ($record->values[$name] ?? '')),
            self::CODE => self::text($key->code),
            self::STATUS => Value::choice([$record->status->value], self::statusText($record->status)),
            self::LINK => self::text($link),
        ]);
    }

    /** The word for a status in a mail. */
    public static function statusText(RegistrationStatus $status): string
    {
        // [UNAPPROVED N20] the status words.
        return match ($status) {
            RegistrationStatus::Registered => 'rögzített',
            RegistrationStatus::Waitlisted => 'várólistás',
            RegistrationStatus::Withdrawn => 'visszavont',
            RegistrationStatus::Removed => 'törölt',
        };
    }

    /** @throws SystemFailure when the slot form is not usable now (`no_form`) or its definition has a problem */
    private function definition(RegistrationRecord $record): FormDefinition
    {
        $form = $this->forms->form($record->formId);
        if ($form === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a problem code and a key, never output
            throw new SystemFailure('no_form ' . $record->formId);
        }

        return $this->forms->definition($form);
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
