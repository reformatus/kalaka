<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Catalogue\ActionMailRepository;
use Kalaka\Mail\Catalogue\MailLayoutRepository;

/** Reads an event's mail settings; `kalaka/update-action-mail` and `kalaka/set-event-mail-layout` change them. */
final class ActionMailSettings
{
    public function __construct(
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
        private readonly MailLayoutRepository $layouts = new MailLayoutRepository(),
    ) {
    }

    /**
     * Every action of the catalogue, a missing row as its default (off, no body, wrapped by the layout), and
     * the layout, exactly `{{content}}` until one is set.
     */
    public function ofEvent(int $eventId): EventMailSettings
    {
        $stored = $this->mails->ofEvent($eventId);
        $mails = [];
        foreach (ActionMail::cases() as $action) {
            $entry = $stored[$action->value] ?? null;
            $mails[] = $entry === null
                ? new ActionMailSetting($action, false, null, true)
                : new ActionMailSetting($action, $entry->enabled, $entry->bodyId, $entry->layout);
        }

        return new EventMailSettings($eventId, $this->layouts->of($eventId)->html, $mails);
    }
}
