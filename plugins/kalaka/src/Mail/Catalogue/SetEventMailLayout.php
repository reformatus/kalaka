<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\ActionMailSettings;

/**
 * Sets an event's layout (mail.md, "Configuration": an event-wide layout wraps mails, optional per mail):
 * raw HTML with the placeholder `{{content}}` exactly once and no other placeholder; any other save, an
 * empty one included, is refused. It is stored as written, with no sanitising: only full access (and
 * WP-CLI, the system) runs it, trusted like `unfiltered_html`. A new event's layout is exactly
 * `{{content}}`. Audited like every use case.
 * // [UNAPPROVED N21] the layout command, its placeholder and its trust.
 */
final class SetEventMailLayout implements AdminUseCase
{
    // [UNAPPROVED N20] the refusal's text.
    public const NO_PLACEHOLDER = 'Az elrendezésben a {{content}} jelölőnek pontosan egyszer kell szerepelnie.';

    public function __construct(
        private readonly MailLayoutRepository $layouts = new MailLayoutRepository(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-event-mail-layout';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'html' => ['type' => 'string'],
            ],
            'required' => ['event_id', 'html'],
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
     * @throws RuleViolation when the HTML does not hold `{{content}}` exactly once
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $this->events->find($eventId) ?? throw new NotFound();
        $html = (string) $input['html'];
        $context->touch(MailLayoutRepository::subjectOf($eventId));
        if (!MailLayout::isValid($html)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
            throw new RuleViolation(self::NO_PLACEHOLDER);
        }

        $layout = $this->layouts->findForUpdate($eventId, $context) ?? new MailLayout($eventId, $html);
        $layout->html = $html;
        $this->layouts->upsert($layout, $context);

        return (new ActionMailSettings(layouts: $this->layouts))->ofEvent($eventId)->toArray();
    }
}
