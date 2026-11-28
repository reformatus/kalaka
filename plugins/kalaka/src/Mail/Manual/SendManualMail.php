<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Admin\Api\GridSelection;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailHold;
use Kalaka\Mail\Api\MailKind;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Api\ManualMails;
use Kalaka\Mail\Catalogue\BodyUnpublished;
use Kalaka\Mail\Catalogue\MailBody;
use Kalaka\Mail\Pause\MailStateQuery;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\Registrations;

/**
 * Sends a chosen mail body to chosen registrations of an event (mail.md, "Five kinds of mail", Manual): one send
 * batch, and one pending delivery per registration, keyed by the batch. The registrations are the input's
 * `selection`: a list of codes, or the rows of one of the viewer's held views that the grid's filters and search
 * leave (`{view, grant, filters?, search?}`, every page: owner ruling 2026-11-26), resolved to codes by
 * `prepared()` and read again at every send. Nothing is sent here: the sender sends
 * (mail.md, "Sending"). It is an event use case carried by the grant action `kalaka_manual_mail`, usable on
 * registrations inside the grant's view, so a grant holds the call only when its view holds every code.
 *
 * One transaction for the whole batch (the batch is one act, not a bulk change):
 * nothing in it can break per registration, since it takes no row lock and only inserts. It reads no
 * WordPress data in its transaction: the body's facts (`MailBody`) and the codes of a view selection are read
 * before the run by `prepared()`, which the ability's `$prepare` and `Api\ManualMails::send()` both call; run
 * without them it refuses. The access check then runs on the resolved codes, so a grant holder reaches only
 * codes inside a view of theirs that carries the action, whichever form the selection took. Its result carries
 * the caller's warnings, `paused` and `held`, on every path.
 *
 * - A registration that is not registered (withdrawn, removed or waitlisted) is skipped and listed.
 * - A code that is no registration of the event refuses the whole batch (`UnknownRegistrationCode`); a selection
 *   that matches no row is refused (`InvalidInput`).
 * - The optional `request_key` is the repeat guard: the same key in the same event returns the first batch and
 *   queues nothing; the same key with another body or other codes is refused, never merged.
 * - It runs no evaluation: it writes no registration, and a delivery maps to no registration lock.
 */
final class SendManualMail implements AdminUseCase
{
    public function __construct(
        private readonly ?MailBody $body = null,
        private readonly Registrations $registrations = new Registrations(),
        private readonly MailBatchRepository $batches = new MailBatchRepository(),
        private readonly MailBatchMemberRepository $members = new MailBatchMemberRepository(),
        private readonly MailBatchQuery $query = new MailBatchQuery(),
        private readonly MailQueue $queue = new MailQueue(),
        private readonly Events $events = new Events(),
        private readonly MailStateQuery $state = new MailStateQuery(),
        /** @var ?list<string> the codes of a view selection, read by `prepared()` */
        private readonly ?array $resolved = null,
    ) {
    }

    /**
     * The use case for this input, with the facts of its body read now, outside any run, and a view selection
     * resolved to codes under the actor's held view: the one pre-read, called by the ability's `$prepare` and by
     * `Api\ManualMails::send()` alike. It only reads, and tolerates input the run has not checked yet: input
     * that does not fit the schema is left unresolved, and the run refuses it.
     *
     * @param array<string, mixed> $input
     * @param ?Actor $actor who sends; the current user when null (the ability's call)
     * @throws NotFound for a view selection in an event that does not exist
     * @throws \Kalaka\Foundation\Api\Error\PermissionDenied when the actor holds no such view
     * @throws InvalidInput for a filter or search the grid would refuse
     */
    public static function prepared(array $input, ?Actor $actor = null): self
    {
        $bodyId = $input['body_id'] ?? null;
        $body = is_int($bodyId) && $bodyId > 0 ? MailBody::read($bodyId) : null;
        try {
            $checked = Runner::checkedInput((new self())->inputSchema(), $input, self::name());
        } catch (InvalidInput) {
            return new self($body);
        }

        return new self($body, resolved: self::resolve($checked, $actor ?? new UserActor(get_current_user_id())));
    }

    /**
     * The use case for an input already checked against the schema, with no body: for the preview, which
     * runs the same access check and the same plan on the same resolved codes.
     *
     * @param array<string, mixed> $checked `event_id` and `selection`, as the runner's input check left them
     * @throws NotFound for a view selection in an event that does not exist
     * @throws \Kalaka\Foundation\Api\Error\PermissionDenied when the actor holds no such view
     * @throws InvalidInput for a filter or search the grid would refuse
     */
    public static function resolving(array $checked, Actor $actor): self
    {
        return new self(null, resolved: self::resolve($checked, $actor));
    }

    /**
     * The codes of a view selection, as the grid's rows route reads them (`Admin\Api\GridSelection`); null for
     * a selection that lists its codes.
     *
     * @param array<string, mixed> $checked
     * @return ?list<string>
     */
    private static function resolve(array $checked, Actor $actor): ?array
    {
        $selection = $checked['selection'] ?? null;
        if (!is_array($selection) || !isset($selection['view'])) {
            return null;
        }
        $grant = $selection['grant'] ?? null;

        return (new GridSelection())->codes(
            $actor,
            (int) $checked['event_id'],
            (int) $selection['view'],
            is_int($grant) ? $grant : null,
            $selection['filters'] ?? null,
            $selection['search'] ?? null
        );
    }

    /**
     * The `selection` property of the input: the codes to send to, or the rows of a held view the grid's filters
     * and search leave. The one schema of the ability, the preview route and the PHP entry.
     *
     * @return array<string, mixed>
     */
    public static function selectionSchema(): array
    {
        return [
            'oneOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'codes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 16],
                            'minItems' => 1,
                            'uniqueItems' => true,
                        ],
                    ],
                    'required' => ['codes'],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'view' => ['type' => 'integer', 'minimum' => 0],
                        'grant' => ['type' => ['integer', 'null'], 'minimum' => 0],
                        // The grid's own `filters`, decoded: a list of {field, operator, value}. `GridNarrowing`
                        // checks each one and refuses what the grid refuses.
                        'filters' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'maxItems' => Narrowing::MAX_FILTERS,
                        ],
                        'search' => ['type' => 'string', 'maxLength' => Narrowing::MAX_SEARCH],
                    ],
                    'required' => ['view'],
                    'additionalProperties' => false,
                ],
            ],
        ];
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
                'selection' => self::selectionSchema(),
                'request_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,64}$'],
            ],
            'required' => ['event_id', 'body_id', 'selection'],
            'additionalProperties' => false,
        ];
    }

    public function action(): string
    {
        return ManualMails::GRANT_ACTION;
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        $eventId = (int) $input['event_id'];

        return array_map(
            static fn (string $code): RegistrationKey => new RegistrationKey($eventId, $code),
            $this->codesOf($input)
        );
    }

    /**
     * @return array{
     *     batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool,
     *     paused: bool, held: bool,
     * } `paused`: sending is paused now; `held`: the event is not published: the mail waits (a warning
     *   for the caller, who may be a grant holder that cannot read the sender's state)
     * @throws NotFound no such event
     * @throws UnknownRegistrationCode a code is no registration of the event
     * @throws InvalidInput the selection matches no row, the body is of another event, or the request key was
     *   used with another body or codes
     * @throws BodyUnpublished the body is not published
     * @throws SystemFailure the body's facts were not read before the run
     */
    public function execute(array $input, Context $context): array
    {
        $eventId = (int) $input['event_id'];
        $bodyId = (int) $input['body_id'];
        $codes = $this->codesOf($input);
        if ($codes === []) {
            throw new InvalidInput();
        }
        $requestKey = isset($input['request_key']) ? (string) $input['request_key'] : null;

        $event = $this->events->find($eventId) ?? throw new NotFound();
        $warnings = [
            'paused' => $this->state->current()->pausedAt !== null,
            'held' => MailHold::isHeld($event, $this->events),
        ];
        $body = $this->body($bodyId);
        if ($body->eventId !== $eventId) {
            throw new InvalidInput();
        }
        // A repeat is answered as one even when the body was unpublished since the first send.
        if ($requestKey !== null) {
            $stored = $this->query->ofRequestKey($eventId, $requestKey);
            if ($stored !== null) {
                return $this->repeat($stored, $bodyId, $codes, $warnings);
            }
        }
        if (!$body->published) {
            throw new BodyUnpublished();
        }

        $plan = self::plan($codes, $this->registrations->statusesOf($eventId, $codes));
        self::refuseUnknown($plan);
        $outcomes = $plan['outcomes'];
        $queued = count($plan['queue']);

        $batch = $this->batches->insert(new MailBatch(
            $eventId,
            $bodyId,
            $requestKey,
            $context->now(),
            self::userOf($context->actor()),
            $queued,
            count($codes) - $queued,
        ), $context);
        if ($batch === null) {
            // The same key in a run that finished first (the unique key made this insert wait for it).
            $stored = $requestKey === null ? null : $this->query->ofRequestKey($eventId, $requestKey);

            return $stored === null ? throw new NotFound() : $this->repeat($stored, $bodyId, $codes, $warnings);
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
        ] + $warnings;
    }

    /**
     * The one plan of a send: which of the codes are queued, which are skipped and why, and which are no
     * registration of the event. The use case runs it, and the command line's preview shows it.
     *
     * @param list<string> $codes
     * @param array<string, RegistrationStatus> $statuses the status of each code that is a registration of the event
     * @return array{
     *     queue: list<string>, skipped: list<array{code: string, reason: string}>, unknown: list<string>,
     *     outcomes: array<string, string>,
     * }
     */
    public static function plan(array $codes, array $statuses): array
    {
        $plan = ['queue' => [], 'skipped' => [], 'unknown' => [], 'outcomes' => []];
        foreach ($codes as $code) {
            $status = $statuses[$code] ?? null;
            if ($status === null) {
                $plan['unknown'][] = $code;

                continue;
            }
            $outcome = MailBatchMember::outcomeOf($status);
            $plan['outcomes'][$code] = $outcome;
            if ($outcome === MailBatchMember::QUEUED) {
                $plan['queue'][] = $code;
            } else {
                $plan['skipped'][] = ['code' => $code, 'reason' => MailBatchMember::reasonOf($outcome)];
            }
        }

        return $plan;
    }

    /**
     * The one refusal of a plan with a code that is no registration of the event: the use case runs it, and the
     * command line before it asks.
     *
     * @param array{unknown: list<string>} $plan
     * @throws UnknownRegistrationCode
     */
    public static function refuseUnknown(array $plan): void
    {
        if ($plan['unknown'] !== []) {
            throw new UnknownRegistrationCode();
        }
    }

    /**
     * The first batch of a request key, when this call asks for the same thing.
     *
     * @param list<string> $codes
     * @param array{paused: bool, held: bool} $warnings
     * @return array{
     *     batch_id: int, queued: int, skipped: list<array{code: string, reason: string}>, repeat: bool,
     *     paused: bool, held: bool,
     * }
     * @throws InvalidInput when the body or the codes differ
     */
    private function repeat(MailBatch $stored, int $bodyId, array $codes, array $warnings): array
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
        ] + $warnings;
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
                $skipped[] = ['code' => (string) $code, 'reason' => MailBatchMember::reasonOf($outcome)];
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
     * The codes the selection names: the listed ones, or those `prepared()` or `resolving()` read from the view.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     * @throws SystemFailure when a view selection was not resolved before the run
     */
    public function codesOf(array $input): array
    {
        $selection = (array) ($input['selection'] ?? []);
        if (isset($selection['codes'])) {
            return array_values(array_map('strval', (array) $selection['codes']));
        }
        if ($this->resolved === null) {
            $error = new SystemFailure(null, new \LogicException('The selection was not resolved before the run.'));
            throw $error;
        }

        return $this->resolved;
    }

    /**
     * The pre-read facts of the body; refused without them, since they cannot be read here.
     *
     * @throws SystemFailure when they were not read for this body: the caller's programming error
     */
    private function body(int $id): MailBody
    {
        if ($this->body === null || $this->body->id !== $id) {
            $error = new SystemFailure(null, new \LogicException('The body was not read before the run.'));
            throw $error;
        }

        return $this->body;
    }
}
