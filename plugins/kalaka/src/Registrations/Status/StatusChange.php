<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Assignments\Api\AssignmentSeats;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventCapacity;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;
use Kalaka\Registrations\Api\RegistrationStatus;
use Kalaka\Registrations\Api\SeatClaim;
use Kalaka\Registrations\Api\SeatClaimSource;
use Kalaka\Registrations\Api\SeatClaims;
use Kalaka\Registrations\Api\SeatOutcome;
use Kalaka\Registrations\Api\SeatPool;
use Kalaka\Registrations\Api\Seats;
use Kalaka\Registrations\Api\StatusAct;
use Kalaka\Registrations\Api\StatusActRefused;
use Kalaka\Registrations\Registration;
use Kalaka\Registrations\RegistrationRepository;

/**
 * The one shape of the four status acts (registrations-and-identity.md, "Status, capacity and waitlist"; money.md,
 * "Withdrawal, removal and substitution"): admit from the waitlist, withdraw, remove and restore, each a staff act on
 * one registration, carried by its grant action. The act writes only its facts; the runner's evaluation then follows
 * in the same run (money, then confirmation, then mail). [UNAPPROVED N510]
 *
 * - Input: the registration and a reason (at most 500 characters; required for remove, optional for the others,
 *   kept in the audit entry's reason). [UNAPPROVED N514] With `preview` the act answers what it would do and writes
 *   nothing (the same refusals, the same grant, plain reads only). [UNAPPROVED N513]
 * - The same act on a registration already in its target state is `{changed: false}` and writes nothing; any other
 *   status the act does not allow is `StatusActRefused`. [UNAPPROVED N512]
 * - Leaving (withdraw, remove): the status and the version; every seat is freed (`Seats::releaseAll()`). Nobody on
 *   the waitlist moves up. `registered_at` and `registered_by` stay as they were.
 * - Coming back (admit, restore): the status, `registered_at` (now) and `registered_by`, then every seat is taken
 *   again through `Seats::retake()`: the claims of the stored values, the event's own places and the confirmed
 *   assignments' options. A full pool never refuses a staff act: the seat goes over the limit and the result says
 *   so (`over`); the event's "full" latch is untouched. [UNAPPROVED N510, N511]
 * - Lock order: the event row, the claimed quota pools in `Seats::inLockOrder()`, then the registration. The claims
 *   are read from the stored values before the locks and again under the registration's lock; a claim of a quota
 *   pool that was not locked (the values changed in between) refuses the act as an edit conflict, nothing written.
 *   [UNAPPROVED N515]
 * - Admission queues the "registered" mail (caused by the registration, as the submit's mail was); a restore, a
 *   withdrawal and a removal queue none. [UNAPPROVED N517]
 *
 * Returns `{changed, status, over_capacity, seats}`: `seats` lists each pool the act took again (a claimed pool, the
 * event's places, an assignment's option) with `held` or `over`.
 */
abstract class StatusChange implements AdminUseCase
{
    /**
     * @param list<SeatClaimSource>|null $seatClaimSources null: the contributed ones; a test passes its own
     */
    public function __construct(
        private readonly ?array $seatClaimSources = null,
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
        private readonly Events $events = new Events(),
        private readonly EventCapacity $capacity = new EventCapacity(),
        private readonly Seats $seats = new Seats(),
        private readonly AssignmentSeats $assignmentSeats = new AssignmentSeats(),
        private readonly ActionMails $mails = new ActionMails(),
        private readonly Registrations $reads = new Registrations(),
    ) {
    }

    abstract protected function act(): StatusAct;

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'pattern' => '^[0-9]{2}[AKBEGJTZFLRSXI][0-9]{1,13}$'],
                // Exactly `string`: the runner keeps only such a property as the audit entry's reason.
                'reason' => ['type' => 'string', 'maxLength' => 500],
                'preview' => ['type' => 'boolean'],
            ],
            'required' => ['event_id', 'code'],
            'additionalProperties' => false,
        ];
    }

    public function action(): string
    {
        return $this->act()->action();
    }

    public function event(array $input): int
    {
        return (int) $input['event_id'];
    }

    public function targets(array $input): array
    {
        return [new RegistrationKey((int) $input['event_id'], (string) $input['code'])];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     */
    public function execute(array $input, Context $context): array
    {
        $act = $this->act();
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        // The reason: required for a removal, checked on the trimmed text (as the confirmation acts do).
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : '';
        if ($act->reasonRequired() && $reason === '') {
            throw new InvalidInput();
        }

        $event = $this->events->find($key->eventId) ?? throw new NotFound();
        $stored = $this->registrations->find($key) ?? throw new NotFound();
        $status = $stored->status;
        if ($status === $act->target()) {
            return self::unchanged($status);
        }
        if (!$act->allowedFrom($status)) {
            throw new StatusActRefused();
        }

        if (($input['preview'] ?? false) === true) {
            return $this->preview($act, $key, $event);
        }

        return $act->enters() ? $this->enter($act, $key, $event, $context) : $this->leave($act, $key, $context);
    }

    /**
     * What the act would do, from plain reads: nothing is locked or written, and the same refusals apply. The seats
     * are the pools the act would take again, each `over` when the pool is full now (`Seats::wouldOverfill()`, the one
     * "would go over" read), so a dialog warns before the act. [UNAPPROVED N513]
     *
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     */
    private function preview(StatusAct $act, RegistrationKey $key, Event $event): array
    {
        if (!$act->enters()) {
            return self::result($act->target(), false, []);
        }
        $taken = [];
        foreach ($this->seats->inLockOrder($this->claims($event, $key)) as $claim) {
            $taken[] = self::seat($claim->pool->key(), $claim->fieldKey, $this->previewOutcome($claim->pool, $key));
        }
        $eventOutcome = $this->previewOutcome($this->seats->eventPool($key->eventId), $key);
        $taken[] = self::seat(SeatPool::EVENT, null, $eventOutcome);
        foreach ($this->assignmentSeats->pools($key) as $pool) {
            $taken[] = self::seat($pool->key(), null, $this->previewOutcome($pool, $key));
        }

        return self::result($act->target(), $eventOutcome === SeatOutcome::Over, $taken);
    }

    private function previewOutcome(SeatPool $pool, RegistrationKey $key): SeatOutcome
    {
        return $this->seats->wouldOverfill($pool, $key) ? SeatOutcome::Over : SeatOutcome::Held;
    }

    /**
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     */
    private function leave(StatusAct $act, RegistrationKey $key, Context $context): array
    {
        $registration = $this->locked($act, $key, $context);
        if ($registration === null) {
            return self::unchanged($act->target());
        }
        $registration->status = $act->target();
        $this->registrations->upsert($registration, $context);
        $this->seats->releaseAll($key, $context);

        return self::result($act->target(), false, []);
    }

    /**
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     */
    private function enter(StatusAct $act, RegistrationKey $key, Event $event, Context $context): array
    {
        // Plain read of the claims, so the quota pools can be locked before the registration row.
        $planned = $this->claims($event, $key);
        $event = $this->capacity->lockForRegistration($context, $key->eventId);
        $locked = [];
        foreach ($this->seats->inLockOrder($planned) as $claim) {
            if ($claim->pool->lockLevel()->value < LockLevel::Registration->value) {
                $this->seats->lock($claim->pool, $context);
                $locked[$claim->pool->key()] = true;
            }
        }

        $registration = $this->locked($act, $key, $context);
        if ($registration === null) {
            return self::unchanged($act->target());
        }
        // Read again under the lock: a claim of a quota pool nobody locked means the values moved in between.
        $claims = $this->claims($event, $key);
        foreach ($claims as $claim) {
            $quota = $claim->pool->lockLevel()->value < LockLevel::Registration->value;
            if ($quota && !isset($locked[$claim->pool->key()])) {
                $error = new VersionConflict();
                throw $error;
            }
        }

        $registration->status = $act->target();
        $registration->registeredAt = $context->now();
        $registration->registeredBy = $act->registeredBy();
        $this->registrations->upsert($registration, $context);

        $seatAct = $act->seatAct() ?? throw new \LogicException('An act that enters takes seats.');
        $taken = [];
        foreach ($this->seats->inLockOrder($claims) as $claim) {
            $taken[] = self::seat(
                $claim->pool->key(),
                $claim->fieldKey,
                $this->seats->retake($claim->pool, $key, $seatAct, $context)
            );
        }
        $eventOutcome = $this->seats->retake($this->seats->eventPool($key->eventId), $key, $seatAct, $context);
        $taken[] = self::seat(SeatPool::EVENT, null, $eventOutcome);
        $over = [];
        foreach ($this->assignmentSeats->retake($key, $seatAct, $context) as $assignment) {
            $over['option.' . $assignment->setId . '.' . $assignment->optionKey] = true;
        }
        foreach ($this->assignmentSeats->pools($key) as $pool) {
            $outcome = isset($over[$pool->key()]) ? SeatOutcome::Over : SeatOutcome::Held;
            $taken[] = self::seat($pool->key(), null, $outcome);
        }

        if ($act === StatusAct::Admit) {
            $this->mails->due($context, ActionMail::Registered, $key, Subject::registration($key));
        }

        return self::result($act->target(), $eventOutcome === SeatOutcome::Over, $taken);
    }

    /**
     * Locks the registration's row and checks the status again under it: null when it is already in the target
     * state (a repeat that lost the race), else the locked row.
     *
     * @throws NotFound
     * @throws StatusActRefused
     */
    private function locked(StatusAct $act, RegistrationKey $key, Context $context): ?Registration
    {
        $registration = $this->registrations->findForUpdate($key, $context) ?? throw new NotFound();
        if ($registration->status === $act->target()) {
            return null;
        }
        if (!$act->allowedFrom($registration->status)) {
            throw new StatusActRefused();
        }

        return $registration;
    }

    /**
     * The claims of the registration's stored values, one per pool: the seats it holds when `registered`. A source
     * claims only the current entitlement (a refused offer claims nothing).
     *
     * @return list<SeatClaim>
     */
    private function claims(Event $event, RegistrationKey $key): array
    {
        $values = $this->reads->values($key);
        $byPool = [];
        $sources = $this->seatClaimSources ?? Contributions::current()->seatClaimSources();
        foreach (SeatClaims::of($sources, $event, new ValidatedAnswers($values, $values)) as $claim) {
            $byPool[$claim->pool->key()] ??= $claim;
        }

        return array_values($byPool);
    }

    /** @return array{pool: string, field: ?string, outcome: string} */
    private static function seat(string $pool, ?string $field, SeatOutcome $outcome): array
    {
        return ['pool' => $pool, 'field' => $field, 'outcome' => $outcome->value];
    }

    /**
     * @param list<array<string, mixed>> $seats
     * @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>}
     */
    private static function result(RegistrationStatus $status, bool $over, array $seats): array
    {
        return ['changed' => true, 'status' => $status->value, 'over_capacity' => $over, 'seats' => $seats];
    }

    /** @return array{changed: bool, status: string, over_capacity: bool, seats: list<array<string, mixed>>} */
    private static function unchanged(RegistrationStatus $status): array
    {
        return ['changed' => false, 'status' => $status->value, 'over_capacity' => false, 'seats' => []];
    }
}
