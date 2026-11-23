<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockOrder;
use Kalaka\Registrations\EventSeatPool;
use Kalaka\Registrations\RegistrationCount;
use Kalaka\Registrations\RegistrationRepository;
use Kalaka\Registrations\SeatCount;
use Kalaka\Registrations\SeatRecord;
use Kalaka\Registrations\SeatRepository;

/**
 * The one seat rule (foundation.md, "Facts, decisions and computed values"; money.md, "Quota places"). A seat
 * is a fact: one place in one pool (`SeatPool`), held by one registration. It is taken only inside an act on
 * that registration (submit, admission, restore, staff placing), which holds the registration's row, and
 * under a lock on the pool's own row, taken before the seats are counted. It is released only by leaving
 * (`release()`, `releaseAll()`). A freed seat stays free until some registration's own act takes it: nothing
 * here moves a seat from one registration to another.
 *
 * The event's pool has no seat rows: its seats are the counted `registered` registrations (the status is the
 * stored fact), and a submit decides its status by the latch, not by this API. Every other pool's seats are
 * rows of `kalaka_seats`, one per registration and pool.
 *
 * Tiers and offers use the same API: a pool implements `SeatPool` over its own row.
 *
 * Lock order (foundation.md, "Lock order"). A pool whose row sits before the registration row (a quota row) is
 * locked before the registration is: an act on an existing registration calls `lock()` for the pool first, then
 * locks the registration, then takes the seat (the pool row is then held, so `take*()` locks nothing new). A
 * `take*()` that finds the registration held and the quota row not fails the run with the guard's `SystemFailure`
 * (a Quota lock after a Registration lock): it cannot take the pool's lock itself, the registration row being
 * locked already. A submit claims its pools in `inLockOrder()`. Pools at any other level are locked inside
 * `take*()`.
 */
final class Seats
{
    public function __construct(
        private readonly SeatRepository $seats = new SeatRepository(),
        private readonly SeatCount $count = new SeatCount(),
        private readonly RegistrationCount $registered = new RegistrationCount(),
        private readonly RegistrationRepository $registrations = new RegistrationRepository(),
    ) {
    }

    /** The pool of the event's own places. */
    public function eventPool(int $eventId): SeatPool
    {
        return new EventSeatPool($eventId);
    }

    /** The seats held in the pool; a plain read. */
    public function held(SeatPool $pool): int
    {
        return $pool->key() === SeatPool::EVENT
            ? $this->registered->registered($pool->eventId())
            : $this->count->held($pool->eventId(), $pool->key());
    }

    /**
     * The seats left in the pool, never below zero; null when the pool has no limit. A plain read: the
     * value of the form-condition variable `event.seats_left` (the event's pool) or
     * `seats_left.<field key>.<option value>` (a set option's pool, by the ranking/request field key).
     */
    public function left(SeatPool $pool): ?int
    {
        $limit = $pool->limit();

        return $limit === null ? null : max(0, $limit - $this->held($pool));
    }

    /** By how many places the pool stands over its limit (staff placed past it); zero when it does not. */
    public function over(SeatPool $pool): int
    {
        $limit = $pool->limit();

        return $limit === null ? 0 : max(0, $this->held($pool) - $limit);
    }

    /**
     * Whether the registration holds a seat in the pool. A plain read. In the event's pool it holds one when it is
     * `registered` (the status is the seat); in any other when it has a seat row.
     */
    public function holds(SeatPool $pool, RegistrationKey $registration): bool
    {
        if ($pool->key() === SeatPool::EVENT) {
            return $registration->eventId === $pool->eventId()
                && $this->registrations->find($registration)?->status === RegistrationStatus::Registered;
        }

        return $this->seats->find(self::key($registration, $pool)) !== null;
    }

    /**
     * The claims in the order their pool rows may be locked: by lock level, then by table and key as the
     * runner's lock guard compares them (not by pool key, which sorts "10" before "9"). A claim's pool must
     * not be locked in any other order, or the run fails with a lock-order `SystemFailure`.
     *
     * @param list<SeatClaim> $claims
     * @return list<SeatClaim>
     */
    public function inLockOrder(array $claims): array
    {
        usort($claims, static function (SeatClaim $a, SeatClaim $b): int {
            $levels = $a->pool->lockLevel()->value <=> $b->pool->lockLevel()->value;
            if ($levels !== 0) {
                return $levels;
            }
            [$tableA, $keyA] = $a->pool->lockRow();
            [$tableB, $keyB] = $b->pool->lockRow();

            return LockOrder::compare($tableA, $keyA, $tableB, $keyB);
        });

        return $claims;
    }

    /**
     * Locks the pool's own row `FOR UPDATE` and returns the seats left, never below zero; null when the pool
     * has no limit. The check a use case makes before it writes anything, for a pool whose row sits
     * before the registration row in the lock order: call it before the registration row is locked, in
     * `inLockOrder()` when there are several pools.
     */
    public function lock(SeatPool $pool, Context $context): ?int
    {
        $limit = $pool->lock($context);

        return $limit === null ? null : max(0, $limit - $this->held($pool));
    }

    /**
     * Takes a seat for the registration's own act, refusing when the pool is full. A registration that
     * already holds one keeps it. The registration's row must be held; for a quota pool its row must have been
     * locked by `lock()` before (see the class comment).
     *
     * @throws SeatsFull
     */
    public function take(SeatPool $pool, RegistrationKey $registration, SeatAct $act, Context $context): void
    {
        $this->claim($pool, $registration, $act, $context, false, true);
    }

    /**
     * Takes a seat when one is free, for an admission or a restore into a pool that may be full: a full pool
     * gives no seat and no error. [UNAPPROVED N6]
     *
     * @return bool whether the registration holds a seat in the pool now
     */
    public function takeIfFree(SeatPool $pool, RegistrationKey $registration, SeatAct $act, Context $context): bool
    {
        return $this->claim($pool, $registration, $act, $context, false, false);
    }

    /** Takes a seat for staff placing the registration by hand: it may go over the limit. */
    public function takeOver(SeatPool $pool, RegistrationKey $registration, SeatAct $act, Context $context): void
    {
        $this->claim($pool, $registration, $act, $context, true, false);
    }

    /**
     * Frees the registration's seat in the pool; nothing when it holds none. The registration's row must be
     * held. The seat goes back to the pool: no other registration gets it.
     */
    public function release(SeatPool $pool, RegistrationKey $registration, Context $context): void
    {
        $this->assertHeld($registration, $context);
        $seat = $this->seats->findForUpdate(self::key($registration, $pool), $context);
        if ($seat !== null) {
            $this->seats->delete($seat, $context);
        }
    }

    /**
     * Frees every seat the registration holds: withdrawal and removal. A confirmed assignment stays as a record
     * (`Assignments`); a restore or an admission takes the seats again (`AssignmentSeats::retake()`).
     * [UNAPPROVED N6, N10]
     */
    public function releaseAll(RegistrationKey $registration, Context $context): void
    {
        $this->assertHeld($registration, $context);
        foreach ($this->seats->ofRegistration($registration, $context) as $seat) {
            $this->seats->delete($seat, $context);
        }
    }

    /**
     * The pool keys of the seats the registration holds, in key order. A plain read.
     *
     * @return list<string>
     */
    public function poolsOf(RegistrationKey $registration): array
    {
        return array_map(
            static fn (SeatRecord $seat): string => $seat->pool,
            $this->seats->ofRegistration($registration)
        );
    }

    private function claim(
        SeatPool $pool,
        RegistrationKey $registration,
        SeatAct $act,
        Context $context,
        bool $over,
        bool $refuse,
    ): bool {
        $this->assertHeld($registration, $context);
        if ($pool->key() === SeatPool::EVENT || $pool->eventId() !== $registration->eventId) {
            $error = new SystemFailure(
                null,
                new \LogicException('A seat is taken in a pool of the registration\'s event, never the event\'s own.')
            );
            throw $error;
        }
        // The pool's row first: two acts for the last seat wait here, and the second counts the first's seat.
        $limit = $pool->lock($context);
        if ($this->seats->findForUpdate(self::key($registration, $pool), $context) !== null) {
            return true;
        }
        if (!$over && $limit !== null && $this->held($pool) >= $limit) {
            if ($refuse) {
                $error = new SeatsFull();
                throw $error;
            }

            return false;
        }
        $this->seats->upsert(new SeatRecord($registration, $pool->key(), $context->now(), $act), $context);

        return true;
    }

    private function assertHeld(RegistrationKey $registration, Context $context): void
    {
        if (!$context->holdsRegistration($registration)) {
            $error = new SystemFailure(
                null,
                new \LogicException('A seat changes only inside an act that holds the registration row.')
            );
            throw $error;
        }
    }

    /** @return array{registration: RegistrationKey, pool: string} */
    private static function key(RegistrationKey $registration, SeatPool $pool): array
    {
        return ['registration' => $registration, 'pool' => $pool->key()];
    }
}
