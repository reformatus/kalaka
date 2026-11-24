<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Catalogue\MailBody;
use Kalaka\Mail\Catalogue\UpdateActionMail;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * Sends a chosen mail body to chosen registrations of an event (mail.md, "Five kinds of mail", Manual): one send
 * batch, and one pending delivery per registration, keyed by the batch. Nothing is sent here: the sender sends
 * (mail.md, "Sending"). It is an event use case carried by the grant action `kalaka_manual_mail`, usable on
 * registrations inside the grant's view, so a grant holds the call only when its view holds every code.
 *
 * One transaction for the whole batch (the batch is one act, not a bulk change):
 * nothing in it can break per registration, since it takes no row lock and only inserts. It reads no
 * WordPress data in its transaction: the body's facts (`MailBody`) are read before the run by
 * `Api\ManualMails::send()`; run without them it refuses.
 *
 * - A withdrawn or removed registration is skipped and listed; a waitlisted one is sent to.
 * - A code that is no registration of the event refuses the whole batch (`NotFound`).
 * - The optional `request_key` is the repeat guard: the same key in the same event returns the first batch and
 *   queues nothing; the same key with another body or other codes is refused, never merged.
 * - It runs no evaluation: it writes no registration, and a delivery maps to no registration lock.
 */
final class SendManualMail implements AdminUseCase
{
    public const MAX_CODES = 1000;

    public function __construct(
        private readonly ?MailBody $body = null,
        private readonly Registrations $registrations = new Registrations(),
        private readonly MailBatchRepository $batches = new MailBatchRepository(),
        private readonly MailBatchMemberRepository $members = new MailBatchMemberRepository(),
        private readonly MailBatchQuery $query = new MailBatchQuery(),
        private readonly MailQueue $queue = new MailQueue(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/send-manual-mail';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'body_id' => ['type' => 'integer', 'minimum' => 1],
                'codes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
                    'minItems' => 1,
                    'maxItems' => self::MAX_CODES,
                    'uniqueItems' => true,
                ],
                'layout' => ['type' => 'boolean'],
                'request_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'],
            ],
            'required' => ['event_id', 'body_id', 'codes'],
            'additionalProperties' => false,
        ];
    }

    public function action(): string
    {
        return 'kalaka_manual_mail';
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        $eventId = (int) $input['event_id'];

        return array_values(array_map(
            static fn (mixed $code): RegistrationKey => new RegistrationKey($eventId, (string) $code),
            (array) $input['codes']
        ));
    }

    /**
     * @return array{batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool}
     * @throws NotFound no such event, or a code that is no registration of it
     * @throws InvalidInput the body is of another event, or the request key was used with another body or codes
     * @throws RuleViolation the body is not published, or its facts were not read before the run
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $bodyId = (int) $input['body_id'];
        /** @var list<string> $codes */
        $codes = array_values(array_map('strval', (array) $input['codes']));
        $layout = (bool) ($input['layout'] ?? true);
        $requestKey = isset($input['request_key']) ? (string) $input['request_key'] : null;

        $this->events->find($eventId) ?? throw new NotFound();
        $body = $this->body($bodyId);
        if ($body->eventId !== $eventId) {
            throw new InvalidInput();
        }
        // A repeat is answered as one even when the body was unpublished since the first send.
        if ($requestKey !== null) {
            $stored = $this->query->ofRequestKey($eventId, $requestKey);
            if ($stored !== null) {
                return $this->repeat($stored, $bodyId, $codes);
            }
        }
        if (!$body->published) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a fixed text, no input
            throw new RuleViolation(UpdateActionMail::BODY_UNPUBLISHED);
        }

        $statuses = $this->registrations->statusesOf($eventId, $codes);
        foreach ($codes as $code) {
            if (!isset($statuses[$code])) {
                throw new NotFound();
            }
        }
        $outcomes = [];
        foreach ($codes as $code) {
            $outcomes[$code] = MailBatchMember::outcomeOf($statuses[$code]);
        }
        $queued = count(array_filter(
            $outcomes,
            static fn (string $outcome): bool => $outcome === MailBatchMember::QUEUED
        ));

        $batch = $this->batches->insert(new MailBatch(
            $eventId,
            $bodyId,
            $layout,
            $requestKey,
            $context->now(),
            self::userOf($context->actor()),
            $queued,
            count($codes) - $queued,
        ), $context);
        if ($batch === null) {
            // The same key in a run that finished first (the unique key made this insert wait for it).
            $stored = $requestKey === null ? null : $this->query->ofRequestKey($eventId, $requestKey);

            return $stored === null ? throw new NotFound() : $this->repeat($stored, $bodyId, $codes);
        }

        $keys = [];
        foreach ($outcomes as $code => $outcome) {
            $this->members->insert(new MailBatchMember($batch->id, $eventId, $code, $outcome), $context);
            if ($outcome === MailBatchMember::QUEUED) {
                $key = new RegistrationKey($eventId, $code);
                $keys[] = $key;
                $context->touch(Subject::registration($key));
            }
        }
        $this->queue->addAll(
            $context,
            $keys,
            MailBatch::MAIL,
            MailKind::Manual,
            new Subject(MailBatch::CAUSE, $eventId, (string) $batch->id)
        );

        return [
            'batch_id' => $batch->id,
            'queued' => $queued,
            'skipped' => self::skipped($outcomes),
            'repeat' => false,
        ];
    }

    /**
     * The first batch of a request key, when this call asks for the same thing.
     *
     * @param list<string> $codes
     * @return array{batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool}
     * @throws InvalidInput when the body or the codes differ
     */
    private function repeat(MailBatch $stored, int $bodyId, array $codes): array
    {
        $outcomes = [];
        foreach ($this->query->members($stored->id) as $member) {
            $outcomes[$member->code] = $member->outcome;
        }
        $storedCodes = array_keys($outcomes);
        sort($storedCodes);
        $given = $codes;
        sort($given);
        if ($stored->bodyId !== $bodyId || $storedCodes !== $given) {
            throw new InvalidInput();
        }

        return [
            'batch_id' => $stored->id,
            'queued' => $stored->queued,
            'skipped' => self::skipped($outcomes),
            'repeat' => true,
        ];
    }

    /**
     * @param array<string, string> $outcomes code → outcome
     * @return list<array{code: string, reason: string}>
     */
    private static function skipped(array $outcomes): array
    {
        $skipped = [];
        foreach ($outcomes as $code => $outcome) {
            if ($outcome !== MailBatchMember::QUEUED) {
                $skipped[] = [
                    'code' => (string) $code,
                    'reason' => $outcome === MailBatchMember::SKIPPED_WITHDRAWN ? 'withdrawn' : 'removed',
                ];
            }
        }

        return $skipped;
    }

    /** The user who sent it: the user, or the command line's `--user`; none for the system. */
    private static function userOf(Actor $actor): ?int
    {
        return $actor instanceof UserActor || $actor instanceof CliActor ? $actor->userId : null;
    }

    /**
     * The pre-read facts of the body; refused without them, since they cannot be read here.
     *
     * @throws RuleViolation when they were not read for this body
     */
    private function body(int $id): MailBody
    {
        if ($this->body === null || $this->body->id !== $id) {
            $error = new RuleViolation();
            throw $error;
        }

        return $this->body;
    }
}
