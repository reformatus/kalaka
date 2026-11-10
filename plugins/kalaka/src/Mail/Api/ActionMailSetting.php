<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/** One row of an event's action-mail catalogue. */
final readonly class ActionMailSetting
{
    /**
     * @param ?int $bodyId the body's `kalaka_mail` post; null when none is assigned
     * @param bool $layout whether the event's layout wraps the mail, when the event has one
     */
    public function __construct(
        public ActionMail $action,
        public bool $enabled,
        public ?int $bodyId,
        public bool $layout,
    ) {
    }

    /** @return array{mail: string, label: string, enabled: bool, body_id: ?int, layout: bool} */
    public function toArray(): array
    {
        return [
            'mail' => $this->action->value,
            'label' => $this->action->label(),
            'enabled' => $this->enabled,
            'body_id' => $this->bodyId,
            'layout' => $this->layout,
        ];
    }
}
