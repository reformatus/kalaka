<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Events\Api\Event;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Catalogue\ActionMailEntry;
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
     * Why each mail can or cannot be switched on or off now, for the event's mail page: `UpdateActionMail::refuse()`
     * run on a copy of the entry switched on and switched off, the one check the use case itself runs, so no copy
     * of the rule exists. A refusal is `{code, message}` as the use case would give it; `can` is true for a switch
     * that makes a change and is not refused.
     *
     * @param array<int, MailBody> $facts the facts of the bodies already read, by post ID; any other is read here
     * @return array<string, array{
     *     can: array{switch_on: bool, switch_off: bool},
     *     refusals: array{switch_on: ?array{code: string, message: string},
     *         switch_off: ?array{code: string, message: string}},
     * }> by the mail's key
     */
    public function switchesOf(EventMailSettings $settings, Event $event, array $facts): array
    {
        $entries = array_map(
            static fn (ActionMailSetting $mail): ActionMailEntry => new ActionMailEntry(
                $settings->eventId,
                $mail->action,
                $mail->enabled,
                $mail->bodyId
            ),
            $settings->mails
        );
        $switches = [];
        foreach ($settings->mails as $mail) {
            $body = $mail->bodyId === null ? null : ($facts[$mail->bodyId] ?? MailBody::read($mail->bodyId));
            $refusals = [];
            foreach (['switch_on' => true, 'switch_off' => false] as $key => $enabled) {
                $after = new ActionMailEntry($settings->eventId, $mail->action, $enabled, $mail->bodyId);
                try {
                    $stored = $mail->bodyId;
                    UpdateActionMail::refuse($event, $mail->action, $after, $stored, $mail->enabled, $body, $entries);
                    $refusals[$key] = null;
                } catch (RuleViolation $refused) {
                    $refusals[$key] = ['code' => $refused->errorCode(), 'message' => $refused->publicMessage()];
                }
            }
            $switches[$mail->action->value] = [
                'can' => [
                    'switch_on' => !$mail->enabled && $refusals['switch_on'] === null,
                    'switch_off' => $mail->enabled && $refusals['switch_off'] === null,
                ],
                'refusals' => ['switch_on' => $refusals['switch_on'], 'switch_off' => $refusals['switch_off']],
            ];
        }

        return $switches;
    }

    /**
     * Changes one action mail (`kalaka/update-action-mail`): the entry for it, the same one the ability runs
     * (`UpdateActionMail::prepared()` reads the body the change concerns, outside the transaction).
     *
     * @param array<string, mixed> $input the use case's input
     * @return array<string, mixed> the event's mail settings after the change (`EventMailSettings::toArray()`)
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function update(array $input, Actor $actor, ?Runner $runner = null): array
    {
        $result = ($runner ?? Runner::current())->run(UpdateActionMail::prepared($input), $input, $actor);

        return is_array($result) ? $result : [];
    }
}
