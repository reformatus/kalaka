<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Events\Api\Events;
use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Catalogue\MailBody;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Mail\Pause\MailStateQuery;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;

/** Sends a chosen mail to chosen registrations (`kalaka/send-manual-mail`) and previews who would get it. */
final class ManualMails
{
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly MailStateQuery $state = new MailStateQuery(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * Runs the use case. The body's facts are read from WordPress first, outside the transaction, and passed
     * in; the pause and the event's publication are read here too, for the caller's warning only.
     *
     * @param array<string, mixed> $input the use case's input
     * @return array{
     *     batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool,
     *     paused: bool, held: bool,
     * }
     * @throws \Kalaka\Foundation\Api\Error\KalakaException anything the run throws
     */
    public function send(array $input, Actor $actor, ?Runner $runner = null): array
    {
        $bodyId = $input['body_id'] ?? null;
        $body = is_int($bodyId) && $bodyId > 0 ? MailBody::read($bodyId) : null;
        /** @var array{batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool} $result */
        $result = ($runner ?? Runner::current())->run(new SendManualMail($body), $input, $actor);
        $eventId = $input['event_id'] ?? null;
        $event = is_int($eventId) ? $this->events->find($eventId) : null;

        return $result + [
            'paused' => $this->state->current()->pausedAt !== null,
            'held' => $event !== null && $event->state() !== EventState::Published,
        ];
    }

    /**
     * What a send of these codes would do, read outside any use case and writing nothing.
     *
     * @param list<string> $codes
     * @return array{queue: list<string>, skipped: list<array{code: string, reason: string}>, unknown: list<string>}
     */
    public function preview(int $eventId, array $codes): array
    {
        $statuses = $this->registrations->statusesOf($eventId, $codes);
        $preview = ['queue' => [], 'skipped' => [], 'unknown' => []];
        foreach ($codes as $code) {
            $status = $statuses[$code] ?? null;
            if ($status === null) {
                $preview['unknown'][] = $code;
            } elseif ($status === RegistrationStatus::Withdrawn || $status === RegistrationStatus::Removed) {
                $preview['skipped'][] = ['code' => $code, 'reason' => $status->value];
            } else {
                $preview['queue'][] = $code;
            }
        }

        return $preview;
    }
}
