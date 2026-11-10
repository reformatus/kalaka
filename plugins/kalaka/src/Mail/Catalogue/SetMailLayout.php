<?php

declare(strict_types=1);

namespace Kalaka\Mail\Catalogue;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\ActionMailSettings;
use Kalaka\Mail\Body\LayoutSlot;
use Kalaka\Mail\Body\MailPosts;

/**
 * Sets or removes an event's layout (mail.md, "Configuration": an event-wide layout wraps mails, optional
 * per mail): a `kalaka_mail` post of the event holding exactly one `kalaka/mail-content` block. Site-level,
 * like every Mail use case.
 * // [UNAPPROVED N16] the command that sets the event-wide layout.
 */
final class SetMailLayout implements AdminUseCase
{
    // [UNAPPROVED N20] the refusal's text.
    public const NO_SLOT = 'Az elrendezésben pontosan egy „Levél szövege” blokknak kell lennie.';

    public function __construct(
        private readonly MailLayoutRepository $layouts = new MailLayoutRepository(),
        private readonly Events $events = new Events(),
        private readonly MailPosts $posts = new MailPosts(),
        private readonly LayoutSlot $slot = new LayoutSlot(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/set-mail-layout';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'layout_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            ],
            'required' => ['event_id', 'layout_id'],
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
     * @throws InvalidInput when the layout is not a `kalaka_mail` post of the event
     * @throws RuleViolation when the layout does not hold exactly one place for the body
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $this->events->find($eventId) ?? throw new NotFound();
        $layoutId = $input['layout_id'] === null ? null : (int) $input['layout_id'];
        $context->touch(MailLayoutRepository::subjectOf($eventId));

        $stored = $this->layouts->findForUpdate($eventId, $context);
        if ($layoutId === null) {
            if ($stored !== null) {
                $this->layouts->delete($stored, $context);
            }
        } else {
            $post = $this->posts->eventOf($layoutId) === $eventId ? get_post($layoutId) : null;
            if (!$post instanceof \WP_Post) {
                throw new InvalidInput();
            }
            if ($this->slot->count($post->post_content) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
                throw new RuleViolation(self::NO_SLOT);
            }
            $layout = $stored ?? new MailLayout($eventId, $layoutId);
            $layout->layoutId = $layoutId;
            $this->layouts->upsert($layout, $context);
        }

        return (new ActionMailSettings(layouts: $this->layouts))->ofEvent($eventId)->toArray();
    }
}
