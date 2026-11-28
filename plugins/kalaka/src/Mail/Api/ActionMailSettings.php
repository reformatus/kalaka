<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Catalogue\ActionMailRepository;
use Kalaka\Mail\Catalogue\MailBody;
use Kalaka\Mail\Catalogue\MailLayoutRepository;
use Kalaka\Mail\Catalogue\UpdateActionMail;

/** Reads an event's mail settings; `kalaka/update-action-mail` and `kalaka/set-event-mail-layout` change them. */
final class ActionMailSettings
{
    public function __construct(
        private readonly ActionMailRepository $mails = new ActionMailRepository(),
        private readonly MailLayoutRepository $layouts = new MailLayoutRepository(),
    ) {
    }

    /**
     * Every action of the catalogue, a missing row as its default (off, no body), and
     * the layout, exactly `{{content}}` until one is set.
     */
    public function ofEvent(int $eventId): EventMailSettings
    {
        $stored = $this->mails->ofEvent($eventId);
        $mails = [];
        foreach (ActionMail::cases() as $action) {
            $entry = $stored[$action->value] ?? null;
            $mails[] = $entry === null
                ? new ActionMailSetting($action, false, null)
                : new ActionMailSetting($action, $entry->enabled, $entry->bodyId);
        }

        return new EventMailSettings($eventId, $this->layouts->of($eventId)->html, $mails);
    }

    /**
     * Changes one action mail (`kalaka/update-action-mail`): the entry for it. The body the change concerns
     * (the one given, else the stored one) is read from WordPress first, outside the transaction, and passed
     * to the use case.
     *
     * @param array<string, mixed> $input the use case's input
     * @return array<string, mixed> the event's mail settings after the change (`EventMailSettings::toArray()`)
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function update(array $input, Actor $actor, ?Runner $runner = null): array
    {
        $bodyId = array_key_exists('body_id', $input) ? $input['body_id'] : $this->storedBody($input);
        $body = is_int($bodyId) && $bodyId > 0 ? MailBody::read($bodyId) : null;
        $result = ($runner ?? Runner::current())->run(new UpdateActionMail($body), $input, $actor);

        return is_array($result) ? $result : [];
    }

    /** @param array<string, mixed> $input */
    private function storedBody(array $input): ?int
    {
        $eventId = $input['event_id'] ?? null;
        $mail = $input['mail'] ?? null;
        if (!is_int($eventId) || !is_string($mail)) {
            return null;
        }

        return ($this->mails->ofEvent($eventId)[$mail] ?? null)?->bodyId;
    }
}
