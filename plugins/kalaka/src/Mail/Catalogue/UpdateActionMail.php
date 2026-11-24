<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Events\Api\AccountMode;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Body\PasswordLinkRequired;
use Kalaka\Mail\Body\RegisteredMailStaysOn;

/**
 * Changes one action mail of an event (mail.md, "Configuration"): switches it on or off, assigns its body
 * (a `kalaka_mail` post of the event) and whether the event's layout wraps it. A mail is on only with a
 * body, a body serves one mail only (mail.md, "Words": one to one), and only a published body is assigned or
 * switched on (a mail switched off may keep a body that is no longer published). Site-level, like every Mail
 * use case: only full access, WP-CLI and the system run it.
 *
 * It reads no WordPress data in its transaction: the body's facts (`MailBody`) are read before the run and
 * passed in, by `prepared()`, which the ability's `$prepare` and `Api\ActionMailSettings::update()` both call.
 * Run without them, it refuses any change that needs them
 * (a body assigned, a mail switched on); a layout switch or a switch-off still passes.
 */
final class UpdateActionMail implements AdminUseCase
{
    public const NO_BODY = 'Előbb add meg a sablon szövegét.';
    public const BODY_TAKEN = 'Ezt a levélsablont már egy másik levél használja.';
    public const BODY_UNPUBLISHED = 'Előbb tedd közzé a levélsablont.';

    public function __construct(
        private readonly ?MailBody $body = null,
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * The pre-read facts of the body; refused without them, since they cannot be read here.
     *
     * @throws SystemFailure when they were not read for this body: the caller's programming error
     */
    private function body(int $id): MailBody
    {
        if ($this->body === null || $this->body->id !== $id) {
            $error = new SystemFailure(null, new \LogicException('The body was not read before the run.'));
            throw $error;
        }

        return $this->body;
    }

    public static function name(): string
    {
        return 'kalaka/update-action-mail';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'mail' => ['type' => 'string', 'enum' => array_column(ActionMail::cases(), 'value')],
                'enabled' => ['type' => 'boolean'],
                'body_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'layout' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'mail'],
            'minProperties' => 3,
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    /**
     * The use case for this input, with the facts of the body the change concerns (the one given, else the
     * stored one) read now, outside any run: the one pre-read, called by the ability's `$prepare` and by
     * `Api\ActionMailSettings::update()` alike. It only reads, and tolerates input the run has not checked yet.
     *
     * @param array<string, mixed> $input
     */
    public static function prepared(array $input): self
    {
        $bodyId = array_key_exists('body_id', $input) ? $input['body_id'] : self::storedBody($input);

        return new self(is_int($bodyId) && $bodyId > 0 ? MailBody::read($bodyId) : null);
    }

    /** @param array<string, mixed> $input */
    private static function storedBody(array $input): ?int
    {
        $eventId = $input['event_id'] ?? null;
        $mail = $input['mail'] ?? null;
        if (!is_int($eventId) || !is_string($mail)) {
            return null;
        }

        return ((new ActionMailRepository())->ofEvent($eventId)[$mail] ?? null)?->bodyId;
    }

    /**
     * The one check of what a change leaves an action mail as: the use case runs it, and the event's mail read
     * runs it too, on a copy of the entry switched on or off, to say why a switch is refused. `$after` is the
     * entry as the change would leave it, `$storedBody` and `$wasEnabled` as it is stored now, `$body` the
     * facts of `$after`'s body, and `$others` the event's stored entries.
     *
     * @param list<ActionMailEntry> $others
     * @throws MailNeedsBody when the mail would be on without a body
     * @throws BodyTaken when another action mail of the event uses the body
     * @throws BodyUnpublished when the body newly assigned, or the one a mail is newly switched on with, is
     *         not published
     * @throws PasswordLinkRequired when the "registered" mail of an event with accounts gets a body without the link
     * @throws RegisteredMailStaysOn when it is switched off in such an event
     * @throws SystemFailure when the body's facts were not read for this body: the caller's programming error
     */
    public static function refuse(
        Event $event,
        ActionMail $action,
        ActionMailEntry $after,
        ?int $storedBody,
        bool $wasEnabled,
        ?MailBody $body,
        array $others,
    ): void {
        foreach ($others as $other) {
            if ($after->bodyId !== null && $other->action !== $action && $other->bodyId === $after->bodyId) {
                throw new BodyTaken();
            }
        }
        if ($after->enabled && $after->bodyId === null) {
            throw new MailNeedsBody();
        }
        // Judged only for what the change alters: a body newly assigned, or the mail switched on. Another edit
        // (the layout switch) of a mail that is on passes.
        $changed = $after->bodyId !== $storedBody || ($after->enabled && !$wasEnabled);
        $facts = null;
        if ($changed && $after->bodyId !== null) {
            if ($body === null || $body->id !== $after->bodyId) {
                $error = new SystemFailure(null, new \LogicException('The body was not read before the run.'));
                throw $error;
            }
            $facts = $body;
            if (!$facts->published) {
                throw new BodyUnpublished();
            }
        }
        // S15: while the event has accounts, "Jelentkezés rögzítve" carries the set-password link (`AccountLinkRule`).
        if ($action === ActionMail::Registered && $event->accountMode !== AccountMode::Off) {
            if ($wasEnabled && !$after->enabled) {
                throw new RegisteredMailStaysOn();
            }
            if ($facts !== null && $after->enabled && !$facts->readsPasswordLink) {
                throw new PasswordLinkRequired();
            }
        }
    }

    /**
     * @return array<string, mixed> the event's mail settings after the change (`EventMailSettings::toArray()`)
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the body is not a `kalaka_mail` post of the event
     * @throws RuleViolation what `refuse()` throws: the mail would be on without a body, the body is another
     *         mail's, the body assigned or switched on is not published, or the account rule of "registered"
     * @throws SystemFailure when the body's facts were not read before the run
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $event = $this->events->find($eventId) ?? throw new NotFound();
        $action = ActionMail::from((string) $input['mail']);

        $entry = $this->mails->findForUpdate(['eventId' => $eventId, 'action' => $action], $context)
            ?? new ActionMailEntry($eventId, $action);
        $context->touch(ActionMailRepository::subjectOf($eventId, $action));
        $wasEnabled = $entry->enabled;
        $storedBody = $entry->bodyId;

        if (array_key_exists('body_id', $input)) {
            $bodyId = $input['body_id'] === null ? null : (int) $input['body_id'];
            if ($bodyId !== null && $this->body($bodyId)->eventId !== $eventId) {
                throw new InvalidInput();
            }
            $entry->bodyId = $bodyId;
        }
        if (array_key_exists('enabled', $input)) {
            $entry->enabled = (bool) $input['enabled'];
        }
        if (array_key_exists('layout', $input)) {
            $entry->layout = (bool) $input['layout'];
        }
        $others = array_values($this->mails->ofEvent($eventId));
        self::refuse($event, $action, $entry, $storedBody, $wasEnabled, $this->body, $others);

        $this->mails->upsert($entry, $context);

        return (new ActionMailSettings($this->mails))->ofEvent($eventId)->toArray();
    }
}
