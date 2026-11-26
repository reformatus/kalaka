<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Manual\MailBatch;
use Kalaka\Mail\Manual\MailBatchQuery;
use Kalaka\Mail\Manual\SendManualMail;
use Kalaka\Registrations\Api\Registrations;

/**
 * Sends a chosen mail to chosen registrations (`kalaka/send-manual-mail`), previews who would get it, and reads
 * the batches sent before.
 */
final class ManualMails
{
    /**
     * The grant action that carries `kalaka/send-manual-mail`: a grant holds it for the codes inside its view.
     * The use case's `action()` and the module's grant actions both name it from here.
     */
    public const GRANT_ACTION = 'kalaka_manual_mail';

    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
        private readonly Events $events = new Events(),
        private readonly MailBatchQuery $batches = new MailBatchQuery(),
        private readonly MailBodies $bodies = new MailBodies(),
    ) {
    }

    /**
     * Runs the use case, the same one the ability runs: `SendManualMail::prepared()` reads the body's facts
     * from WordPress first, outside the transaction, and the result carries the pause and the event's hold as
     * the use case returns them.
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
        /** @var array{batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool, paused: bool, held: bool} $result */
        $result = ($runner ?? Runner::current())->run(SendManualMail::prepared($input), $input, $actor);

        return $result;
    }

    /**
     * What a send of these codes would do, checked first and writing nothing: the shape of `event_id` and
     * `codes` checked and sanitized by the Runner's own input check on the use case's schema, then the actor's
     * access to those codes (`AdminAccess::check()`,
     * the use case's own check, so an unknown code and a code outside the actor's view give the same refusal),
     * then the event, and only then the use case's own plan (`SendManualMail::plan()`).
     *
     * @param array<string, mixed> $input `event_id` and `codes`
     * @return array{
     *     queue: list<string>, skipped: list<array{code: string, reason: string}>, unknown: list<string>,
     *     outcomes: array<string, string>,
     * }
     * @throws InvalidInput when `event_id` or `codes` are not as the use case's schema has them
     * @throws \Kalaka\Foundation\Api\Error\PermissionDenied when the actor's access does not hold every code
     * @throws NotFound when there is no such event
     */
    public function preview(array $input, Actor $actor): array
    {
        $useCase = new SendManualMail();
        $schema = $useCase->inputSchema();
        $shape = [
            'type' => 'object',
            'properties' => array_intersect_key((array) $schema['properties'], ['event_id' => 1, 'codes' => 1]),
            'required' => ['event_id', 'codes'],
            'additionalProperties' => false,
        ];
        $input = Runner::checkedInput($shape, $input, $useCase::name());
        AdminAccess::check($useCase, $input, $actor);

        $eventId = (int) $input['event_id'];
        $this->events->find($eventId) ?? throw new NotFound();
        /** @var list<string> $codes */
        $codes = array_values(array_map('strval', (array) $input['codes']));

        return SendManualMail::plan($codes, $this->registrations->statusesOf($eventId, $codes));
    }

    /**
     * The event's batches, newest first, with the subject of each one's body: the batch's own record, never the
     * deliveries (so no sent or failed counts). One statement, and one read of the bodies' titles.
     *
     * @return list<BatchInfo>
     */
    public function batches(int $eventId): array
    {
        $batches = $this->batches->ofEvent($eventId);
        $subjects = $this->bodies->subjects(array_values(array_unique(array_map(
            static fn (MailBatch $batch): int => $batch->bodyId,
            $batches
        ))));

        return array_map(
            static fn (MailBatch $batch): BatchInfo => BatchInfo::of($batch, $subjects[$batch->bodyId] ?? null),
            $batches
        );
    }

    /**
     * One batch of the event with its members, as the batch recorded them; null for a batch of another event or
     * none. Two statements.
     */
    public function batch(int $eventId, int $id): ?BatchInfo
    {
        $batch = $this->batches->find($eventId, $id);
        if ($batch === null) {
            return null;
        }
        $members = array_map(
            static fn ($member): array => ['code' => $member->code, 'outcome' => $member->outcome],
            $this->batches->members($batch->id)
        );

        return BatchInfo::of($batch, $this->bodies->subjects([$batch->bodyId])[$batch->bodyId] ?? null, $members);
    }
}
