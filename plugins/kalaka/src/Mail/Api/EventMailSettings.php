<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * An event's mail settings: its layout and its action-mail catalogue, every action present, in catalogue
 * order. mail.md, "Configuration".
 * // [UNAPPROVED N16] the read model of the per-event mail settings.
 */
final readonly class EventMailSettings
{
    /**
     * @param ?int $layoutId the event-wide layout's `kalaka_mail` post; null when the event has none
     * @param list<ActionMailSetting> $mails
     */
    public function __construct(
        public int $eventId,
        public ?int $layoutId,
        public array $mails,
    ) {
    }

    public function mail(ActionMail $action): ActionMailSetting
    {
        foreach ($this->mails as $mail) {
            if ($mail->action === $action) {
                return $mail;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an action key, never output
        throw new \LogicException('Every action is in the catalogue: ' . $action->value);
    }

    /**
     * @return array{
     *     event_id: int,
     *     layout_id: ?int,
     *     mails: list<array{mail: string, label: string, enabled: bool, body_id: ?int, layout: bool}>
     * }
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'layout_id' => $this->layoutId,
            'mails' => array_map(static fn (ActionMailSetting $mail): array => $mail->toArray(), $this->mails),
        ];
    }
}
