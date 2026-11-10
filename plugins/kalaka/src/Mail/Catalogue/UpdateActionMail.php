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
use Kalaka\Mail\Body\MailPosts;

/**
 * Changes one action mail of an event (mail.md, "Configuration"): switches it on or off, assigns its body
 * (a `kalaka_mail` post of the event) and whether the event's layout wraps it. A mail is on only with a
 * body. Site-level, like every Mail use case: only full access, WP-CLI and the system run it.
 * // [UNAPPROVED N16] the command that changes one action mail.
 */
final class UpdateActionMail implements AdminUseCase
{
    // [UNAPPROVED N20] the refusal's text.
    public const NO_BODY = 'Előbb add meg a levél szövegét.';

    public function __construct(
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
        private readonly Events $events = new Events(),
        private readonly MailPosts $posts = new MailPosts(),
    ) {
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
     * @throws RuleViolation when the mail would be on without a body
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $this->events->find($eventId) ?? throw new NotFound();
        $action = ActionMail::from((string) $input['mail']);

        $entry = $this->mails->findForUpdate(['eventId' => $eventId, 'action' => $action], $context)
            ?? new ActionMailEntry($eventId, $action);
        $context->touch(ActionMailRepository::subjectOf($eventId, $action));

        if (array_key_exists('body_id', $input)) {
            $bodyId = $input['body_id'] === null ? null : (int) $input['body_id'];
            if ($bodyId !== null && $this->posts->eventOf($bodyId) !== $eventId) {
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
        if ($entry->enabled && $entry->bodyId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
            throw new RuleViolation(self::NO_BODY);
        }

        $this->mails->upsert($entry, $context);

        return (new ActionMailSettings($this->mails))->ofEvent($eventId)->toArray();
    }
}
