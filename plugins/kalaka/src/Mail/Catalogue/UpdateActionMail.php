<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMailSettings;

/**
 * Changes one action mail of an event (mail.md, "Configuration"): switches it on or off, assigns its body
 * (a `kalaka_mail` post of the event) and whether the event's layout wraps it. A mail is on only with a
 * body, a body serves one mail only (mail.md, "Words": one to one), and only a published body is assigned or
 * switched on (a mail switched off may keep a body that is no longer published). Site-level, like every Mail
 * use case: only full access, WP-CLI and the system run it.
 *
 * It reads no WordPress data in its transaction: the body's facts (`MailBody`) are read before the run and
 * passed in, by `Api\ActionMailSettings::update()`. Run without them, it refuses any change that needs them
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
     * @throws RuleViolation when they were not read for this body
     */
    private function body(int $id): MailBody
    {
        if ($this->body === null || $this->body->id !== $id) {
            $error = new RuleViolation();
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
     * @return array<string, mixed> the event's mail settings after the change (`EventMailSettings::toArray()`)
     * @throws NotFound when there is no such event
     * @throws InvalidInput when the body is not a `kalaka_mail` post of the event
     * @throws RuleViolation when the mail would be on without a body, the body is another mail's, the
     *         body assigned or switched on is not published, or its facts were not read before the run
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $this->events->find($eventId) ?? throw new NotFound();
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
            foreach ($this->mails->ofEvent($eventId) as $other) {
                if ($bodyId !== null && $other->action !== $action && $other->bodyId === $bodyId) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
                    throw new RuleViolation(self::BODY_TAKEN);
                }
            }
            $entry->bodyId = $bodyId;
        }
        if (array_key_exists('enabled', $input)) {
            $entry->enabled = (bool) $input['enabled'];
        }
        if (array_key_exists('layout', $input)) {
            $entry->layout = (bool) $input['layout'];
        }
        if ($entry->enabled && $entry->bodyId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
            throw new RuleViolation(self::NO_BODY);
        }
        // Judged only for what this input changes: a body newly assigned, or the mail switched on. Another edit
        // (the layout switch) of a mail that is on passes.
        $changed = $entry->bodyId !== $storedBody || ($entry->enabled && !$wasEnabled);
        if ($changed && $entry->bodyId !== null && !$this->body($entry->bodyId)->published) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
            throw new RuleViolation(self::BODY_UNPUBLISHED);
        }

        $this->mails->upsert($entry, $context);

        return (new ActionMailSettings($this->mails))->ofEvent($eventId)->toArray();
    }
}
