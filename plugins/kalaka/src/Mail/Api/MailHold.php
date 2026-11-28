<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Mail\ClearEventMail;
use Kalaka\Mail\EventMailNotHeld;

/**
 * Whether an event's pending mail is held: the one read of it (mail.md, "Pausing"). The sender sends the
 * deliveries of the events whose stored state is `published`, and an event whose state is anything else holds
 * its mail. The sender, the clearing of a held event's mail and, from #244, the manual send's warning read it
 * here.
 */
final class MailHold
{
    /**
     * The events whose pending mail the sender sends.
     *
     * @return list<int>
     */
    public static function sentEventIds(?Events $events = null): array
    {
        return array_map(static fn (Event $event): int => $event->id, ($events ?? new Events())->published());
    }

    /** Whether the event's pending mail is held: the sender does not send it. */
    public static function isHeld(Event $event, ?Events $events = null): bool
    {
        return !in_array($event->id, self::sentEventIds($events), true);
    }

    /**
     * Whether `ClearEventMail` would accept the event now: a read with no side effects, which asks the use case's
     * own check (`ClearEventMail::refuse()`) so the screens' `can.clear_event` copy no rule. The act itself stays
     * `ClearEventMail`. Only `EventMailNotHeld` means no; anything else the check throws propagates.
     */
    public static function mayClear(Event $event, ?Events $events = null): bool
    {
        try {
            ClearEventMail::refuse($event, $events);
        } catch (EventMailNotHeld) {
            return false;
        }

        return true;
    }
}
