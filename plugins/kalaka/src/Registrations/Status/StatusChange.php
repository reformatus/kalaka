<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Status;

use Kalaka\Admin\Api\Access;
use Kalaka\Assignments\Api\AssignmentSeats;
use Kalaka\Assignments\Api\AssignmentSets;
use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventCapacity;
use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\ValidatedAnswers;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Foundation\Api\UseCase\PreviewableUseCase;
use Kalaka\Ledger\Api\Ledger;
use Kalaka\Mail\Api\ActionMail;
use Kalaka\Mail\Api\ActionMails;
use Kalaka\Pricing\Api\FeeComponentFailed;
use Kalaka\Pricing\Api\FeeComposition;
use Kalaka\Pricing\Api\MoneySettings;
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
 * - Input: the registration and a reason (at most 500 characters; required for a real remove, optional for the
 *   other real acts, kept in the audit entry's reason). [UNAPPROVED N514] With `preview` the act answers what it
 *   would do and writes nothing (the same refusals, the same grant, plain reads only); a preview needs no reason
 *   and ignores one, and the runner writes no audit entry for it. [UNAPPROVED N513, N534, N536]
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
 * Returns `{changed, status, over_capacity, seats, warnings}`: `seats` lists each pool the act took again (a claimed
 * pool, the event's places, an assignment's option) with its label and `held` or `over`; `warnings` holds the "full"
 * warning when any went over. The preview adds, per seat, `taken` (held by other registrations), `taken_after` (the
 * pool's count once the act is done: `taken + 1` for an act that takes the seat, `taken` for one that frees it) and
 * `limit`; a leaving act lists the pools the registration holds now, each with outcome `released`. Withdraw, remove
 * and restore add `money`: per payer `paid`, `balance_now` and `balance_after`, with `money_hidden` (true: the
 * actor has no money-view, `money` is null; false with a null `money`: the fee cannot be priced). Withdraw and
 * remove add `retention`, the event's rule (`everything_paid` or `nothing`), null for a holder without money-view.
 * [UNAPPROVED N530-N533, N535, N537, N538]
 */
abstract class StatusChange implements AdminUseCase, PreviewableUseCase
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
        private readonly Access $access = new Access(),
        private readonly FeeComposition $composition = new FeeComposition(),
        private readonly Ledger $ledger = new Ledger(),
        private readonly Registrations $reads = new Registrations(),
        private readonly MoneySettings $settings = new MoneySettings(),
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

    public function isPreview(array $input): bool
    {
        return ($input['preview'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input, Context $context): array
    {
        $act = $this->act();
        $key = new RegistrationKey((int) $input['event_id'], (string) $input['code']);
        // The reason: required for a real removal, checked on the trimmed text (as the confirmation acts do). A
        // preview does not need one and ignores one it is given (the dialog may preview after staff typed it).
        $preview = $this->isPreview($input);
        $reason = isset($input['reason']) ? trim((string) $input['reason']) : '';
        if (!$preview && $act->reasonRequired() && $reason === '') {
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

        if ($preview) {
            return $this->preview($act, $key, $event, $context);
        }

        return $act->enters() ? $this->enter($act, $key, $event, $context) : $this->leave($act, $key, $context);
    }

    /**
     * What the act would do, from plain reads: nothing is locked or written, and the same refusals apply. The seats
     * are the pools the act would take again, each `over` when the pool is full now (`Seats::wouldOverfill()`, the one
     * "would go over" read), so a dialog warns before the act. [UNAPPROVED N513]
     *
     * @return array<string, mixed>
     */
    private function preview(StatusAct $act, RegistrationKey $key, Event $event, Context $context): array
    {
        $taken = [];
        $eventOutcome = SeatOutcome::Held;
        if (!$act->enters()) {
            $taken = $this->previewReleased($event, $key);
        }
        if ($act->enters()) {
            foreach ($this->seats->inLockOrder($this->claims($event, $key)) as $claim) {
                $taken[] = $this->previewSeat($claim->pool, $claim->fieldKey, $key);
            }
            $eventPool = $this->seats->eventPool($key->eventId);
            $taken[] = $this->previewSeat($eventPool, null, $key);
            $eventOutcome = $this->previewOutcome($eventPool, $key);
            foreach ($this->assignmentSeats->pools($key) as $pool) {
                $taken[] = $this->previewSeat($pool, null, $key);
            }
        }
        $result = self::result($act->target(), $eventOutcome === SeatOutcome::Over, $taken);
        if ($act !== StatusAct::Admit) {
            $hidden = !$this->access->mayViewMoney($context->actor(), $key);
            $result['money'] = $hidden ? null : $this->previewMoney($act, $key, $context);
            $result['money_hidden'] = $hidden;
            if (!$act->enters()) {
                $result['retention'] = $hidden ? null : $this->settings->retention($key->eventId)->value;
            }
        }

        return $result;
    }

    /**
     * The pools a leaving act frees: the ones the registration holds now (claimed pools, the event's places, an
     * assignment's option), each with the count of the others (`taken`), which is also the count after.
     *
     * @return list<array<string, mixed>>
     */
    private function previewReleased(Event $event, RegistrationKey $key): array
    {
        $pools = [];
        foreach ($this->seats->inLockOrder($this->claims($event, $key)) as $claim) {
            $pools[] = [$claim->pool, $claim->fieldKey];
        }
        $pools[] = [$this->seats->eventPool($key->eventId), null];
        foreach ($this->assignmentSeats->pools($key) as $pool) {
            $pools[] = [$pool, null];
        }
        $released = [];
        foreach ($pools as [$pool, $field]) {
            if (!$this->seats->holds($pool, $key)) {
                continue;
            }
            $others = $this->seats->held($pool) - 1;
            $released[] = [
                'pool' => $pool->key(),
                'label' => $pool->label(),
                'field' => $field,
                'outcome' => 'released',
                'taken' => $others,
                'taken_after' => $others,
                'limit' => $pool->limit(),
            ];
        }

        return $released;
    }

    /**
     * One pool the act would take again, as a dialog needs it: its own label, the seats the registrations other
     * than this one hold (`taken`, the count `wouldOverfill()` compares) and its limit (null when it has none).
     *
     * @return array<string, mixed>
     */
    private function previewSeat(SeatPool $pool, ?string $field, RegistrationKey $key): array
    {
        $taken = $this->seats->held($pool) - ($this->seats->holds($pool, $key) ? 1 : 0);

        return self::seat($pool, $field, $this->previewOutcome($pool, $key)) + [
            'taken' => $taken,
            'taken_after' => $taken + 1,
            'limit' => $pool->limit(),
        ];
    }

    /**
     * What the act does to each payer's money, for a holder who may view it: what was paid, the balance now and the
     * balance once the act's own composition (the materializer's, retention included) is recorded. Signed amounts as
     * the money reads give them (above zero owed, below zero overpaid). Null when
     * a fee component cannot say what is owed. Nothing is written or refunded. The caller has checked the holder.
     *
     * @return list<array{payer: string, paid: string, balance_now: string, balance_after: string}>|null
     */
    private function previewMoney(StatusAct $act, RegistrationKey $key, Context $context): ?array
    {
        try {
            $after = $this->composition->of($key, $context->now(), $act->target());
        } catch (FeeComponentFailed) {
            return null;
        }
        $event = $this->events->find($key->eventId);
        if ($after === null || $event === null) {
            return null;
        }
        $summary = $this->ledger->summaryOf($key);
        $targets = $after->targets();
        $payers = array_unique([...array_keys($summary->payers()), ...array_keys($targets)]);
        sort($payers, SORT_STRING);
        $rows = [];
        foreach ($payers as $payer) {
            $payer = (string) $payer;
            $now = $summary->of($payer, $event->currency);
            $target = $targets[$payer] ?? Money::zero($event->currency);
            $rows[] = [
                'payer' => $payer,
                'paid' => $now->paid->amount(),
                'balance_now' => $now->balance->amount(),
                'balance_after' => $target->minus($now->paid)->amount(),
            ];
        }

        return $rows;
    }

    private function previewOutcome(SeatPool $pool, RegistrationKey $key): SeatOutcome
    {
        return $this->seats->wouldOverfill($pool, $key) ? SeatOutcome::Over : SeatOutcome::Held;
    }

    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
                $claim->pool,
                $claim->fieldKey,
                $this->seats->retake($claim->pool, $key, $seatAct, $context)
            );
        }
        $eventPool = $this->seats->eventPool($key->eventId);
        $eventOutcome = $this->seats->retake($eventPool, $key, $seatAct, $context);
        $taken[] = self::seat($eventPool, null, $eventOutcome);
        $over = [];
        foreach ($this->assignmentSeats->retake($key, $seatAct, $context) as $assignment) {
            $over['option.' . $assignment->setId . '.' . $assignment->optionKey] = true;
        }
        foreach ($this->assignmentSeats->pools($key) as $pool) {
            $outcome = isset($over[$pool->key()]) ? SeatOutcome::Over : SeatOutcome::Held;
            $taken[] = self::seat($pool, null, $outcome);
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

    /** @return array{pool: string, label: string, field: ?string, outcome: string} */
    private static function seat(SeatPool $pool, ?string $field, SeatOutcome $outcome): array
    {
        return ['pool' => $pool->key(), 'label' => $pool->label(), 'field' => $field, 'outcome' => $outcome->value];
    }

    /**
     * @param list<array<string, mixed>> $seats
     * @return array<string, mixed>
     */
    private static function result(RegistrationStatus $status, bool $over, array $seats): array
    {
        // The "full" warning (B5-2's edit preview has the same shape) when any pool went, or would go, over.
        $warnings = [];
        foreach ($seats as $seat) {
            if ($seat['outcome'] === SeatOutcome::Over->value) {
                $warnings = [AssignmentSets::FULL_WARNING];
            }
        }

        return [
            'changed' => true,
            'status' => $status->value,
            'over_capacity' => $over,
            'seats' => $seats,
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    private static function unchanged(RegistrationStatus $status): array
    {
        return [
            'changed' => false,
            'status' => $status->value,
            'over_capacity' => false,
            'seats' => [],
            'warnings' => [],
        ];
    }
}
