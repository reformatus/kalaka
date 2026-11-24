<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\EventValues;
use Kalaka\Forms\Api\FormDefinition;
use Kalaka\Forms\Api\Forms;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\Values;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Mail\RecipientUnavailable;
use Kalaka\Registrations\Api\Accounts;
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
 *
 * The owner account's keys (S15, owner ruling 2026-11-16; true, false and null apart, owner ruling 2026-11-18):
 * `account.unverified`, a choice: `yes` while the registration's owner is an account Kaláka created whose
 * password was never set, `no` for an owner who is verified (a social sign-up among them), none for a guest's
 * registration or for an event whose account mode is `off` (no account step, no account facts), or a recipient
 * other than the account's own address. The texts of yes and no are „Igen” and „Nem” (`EventValues::yesNo()`). And
 * `account.password_link`, that account's set-password link, made when the mail is rendered
 * (`Registrations\Api\Accounts::passwordLink()`; the newest link wins), and only for a body that reads it; it is
 * none wherever `account.unverified` is not `yes`, so a link to set someone's password never goes to another
 * address. The link carries the slot form's marker, so the login after the password is set returns to the form
 * (OP1, owner 2026-11-17).
 *
 * The page link (the 2026-11-10 decision, "link to page X"): the key `page_<ID>.link` is the registrant's own
 * secret link on the published page with that ID, so following it makes the registration active there (signing
 * in alone sets none: SB-12). The page is part of the key, so a key is declared only for a page the body places
 * and that is published now; any other is an undeclared key, `BodyUnusable` at send time (a page unpublished or
 * deleted after the mail was queued fails that delivery without pausing). Made only for a body that places it.
 */
final class MailValues
{
    public const RECIPIENT_EMAIL = 'recipient.email';
    public const RECIPIENT_NAME = 'recipient.name';
    public const CODE = 'registration.code';
    public const STATUS = 'registration.status';
    public const LINK = 'registration.link';
    public const ACCOUNT_UNVERIFIED = 'account.unverified';
    public const ACCOUNT_PASSWORD_LINK = 'account.password_link';

    /** [UNAPPROVED T57] the page-link key's shape: `page_<ID>.link`, the ID of a published page. */
    private const PAGE_LINK = '/\Apage_([1-9][0-9]{0,18})\.link\z/D';

    /**
     * Whether the registration was admitted from the waitlist: a choice of `yes` or `no`, with no display text,
     * for a condition in the "registered" mail, which admission sends too (owner 2026-11-16). No admission act
     * exists yet, so it reads `no`; the admission slice gives Registrations the fact and this reads it.
     */
    public const FROM_WAITLIST = 'registration.from_waitlist';

    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly SecretLinks $links = new SecretLinks(),
        private readonly Forms $forms = new Forms(),
        private readonly Events $events = new Events(),
        private readonly Accounts $accounts = new Accounts(),
    ) {
    }

    /**
     * Whether a body places the set-password link: an inline value or a `kalaka/value` binding naming
     * `account.password_link`, as the engine's value walk finds them (a condition naming it is not a value).
     * The save check of the "Jelentkezés rögzítve" mail asks it (S15).
     */
    public static function readsPasswordLink(string $content): bool
    {
        return in_array(self::ACCOUNT_PASSWORD_LINK, (new Values())->placedKeys($content), true);
    }

    /**
     * The IDs of the pages whose link a body places (`page_<ID>.link`), as the engine's value walk finds
     * them; whether such a page exists and is published is `types()`'s.
     *
     * @return list<int>
     */
    public static function pageLinks(string $content): array
    {
        $ids = [];
        foreach ((new Values())->placedKeys($content) as $key) {
            if (preg_match(self::PAGE_LINK, $key, $parts) === 1) {
                $ids[(int) $parts[1]] = (int) $parts[1];
            }
        }

        return array_values($ids);
    }

    /**
     * The keys and types a mail of the event may read: the context's keys, as `context()` declares them, and
     * the page links `$content` places that point at a page published now (`page_<ID>.link`). A
     * body is checked against them at save (`BodyCheck`) and again before the claim, in `MailRenderer`
     * (a failure there is `BodyUnusable`).
     *
     * @return array<string, ValueType>
     */
    public function types(int $eventId, string $content = ''): array
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
            self::FROM_WAITLIST => ValueType::Choice,
            self::ACCOUNT_UNVERIFIED => ValueType::Choice,
            self::ACCOUNT_PASSWORD_LINK => ValueType::Text,
            ...array_fill_keys(
                array_map(
                    static fn (int $page): string => 'page_' . $page . '.link',
                    array_values(array_filter(self::pageLinks($content), self::isPublishedPage(...)))
                ),
                ValueType::Text
            ),
        ];
    }

    /**
     * The participant fields of the event whose value is a path (`PathFieldType`,
     * the congregation field): a mail's condition reads them with `contains`, never `equals`, as a form's
     * does. Checked with `types()`.
     *
     * @return list<string>
     */
    public function paths(int $eventId): array
    {
        return $this->forms->pathKeys($eventId);
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
     * @param bool $passwordLink whether the body reads `account.password_link`: only then is a link made
     * @param list<int> $pages the pages whose link the body places (`pageLinks()`): only those links are made
     * @throws SystemFailure no such registration (`no_registration`) or event (`no_event`), a slot form that
     *         is not usable now, or a field of a type no longer contributed
     */
    public function context(RegistrationKey $key, bool $passwordLink = false, array $pages = []): ValueContext
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
        $recipient = $email === null ? '' : ($record['values'][$email] ?? '');
        $owner = $this->registrations->find($key)?->ownerUserId;
        // True, false and null stay apart (owner ruling 2026-11-18): none unless the event has an account step (mode
        // not `off`: no account step, no account facts), the registration has an owner and the recipient is the
        // owner's own address, then yes (unverified) or no (verified).
        $unverified = $event->accountMode !== AccountMode::Off
            && $owner !== null
            && $this->ownAddress($owner, $recipient)
                ? $this->accounts->isUnverified($owner)
                : null;
        $setPassword = $unverified === true && $passwordLink
            ? $this->accounts->passwordLink($owner, $record['form_id'])
            : null;

        $pageLinks = [];
        foreach ($pages as $page) {
            $url = self::isPublishedPage($page) ? $this->links->url($key, (string) get_permalink($page)) : null;
            $pageLinks['page_' . $page . '.link'] = self::text($url ?? '');
        }

        return new ValueContext([
            ...$pageLinks,
            ...$this->forms->participantValues($key->eventId, $record['values']),
            ...EventValues::of($event),
            self::RECIPIENT_EMAIL => self::text($email === null ? '' : ($record['values'][$email] ?? '')),
            self::RECIPIENT_NAME => self::text($name === null ? '' : ($record['values'][$name] ?? '')),
            self::CODE => self::text($key->code),
            self::STATUS => Value::choice([$record['status']->value], $record['status']->word()),
            self::LINK => self::text($link),
            self::FROM_WAITLIST => Value::choice(['no'], ''),
            self::ACCOUNT_UNVERIFIED => $unverified === null
                ? Value::none(ValueType::Choice)
                : EventValues::yesNo($unverified),
            self::ACCOUNT_PASSWORD_LINK => self::text($setPassword ?? ''),
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
     * the ones the answers were given in. It is read as a stored submission's, never as a form being served:
     * neither a later save that breaks the current version nor an event switched to accounts (whose forms
     * then lack the account block) loses the recipient or the context.
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

    /** Whether the recipient's address is the account's own, as WordPress compares e-mails. */
    private function ownAddress(int $userId, string $recipient): bool
    {
        $user = get_userdata($userId);

        return $user instanceof \WP_User && $recipient !== '' && strcasecmp($user->user_email, $recipient) === 0;
    }

    /** Whether the ID is a published page (`page` post type) that visitors can view. */
    private static function isPublishedPage(int $id): bool
    {
        $post = get_post($id);

        return $post instanceof \WP_Post && $post->post_type === 'page' && is_post_publicly_viewable($post);
    }

    private static function text(string $text): Value
    {
        return $text === '' ? Value::none(ValueType::Text) : Value::text($text, $text);
    }
}
