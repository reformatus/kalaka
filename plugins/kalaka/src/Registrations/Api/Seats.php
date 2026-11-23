<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\EventSeatPool;
use Kalaka\Registrations\RegistrationCount;
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
 * The event's pool has no seat rows: its seats are the `registered` registrations (the status is the
 * stored fact), and a submit decides its status by the latch, not by this API. Every other pool's seats are
 * rows of `kalaka_seats`, one per registration and pool.
 *
 * Tiers and offers use the same API: a pool implements `SeatPool` over its own row.
 */
final class Seats
{
    public function __construct(
        private readonly SeatRepository $seats = new SeatRepository(),
        private readonly SeatCount $count = new SeatCount(),
        private readonly RegistrationCount $registered = new RegistrationCount(),
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
     * value of the form-condition variable `seats_left.<pool>`.
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

    /** Whether the registration holds a seat in the pool. A plain read; never true for the event's pool. */
    public function holds(SeatPool $pool, RegistrationKey $registration): bool
    {
        return $pool->key() !== SeatPool::EVENT && $this->seats->find(self::key($registration, $pool)) !== null;
    }

    /**
     * Locks the pool's own row `FOR UPDATE` and returns the seats left, never below zero; null when the pool
     * has no limit. The check a use case makes before it writes anything, for a pool whose row sits
     * before the registration row in the lock order.
     */
    public function lock(SeatPool $pool, Context $context): ?int
    {
        $limit = $pool->lock($context);

        return $limit === null ? null : max(0, $limit - $this->held($pool));
    }

    /**
     * Takes a seat for the registration's own act, refusing when the pool is full. A registration that
     * already holds one keeps it.
     *
     * @throws SeatsFull
     */
    public function take(SeatPool $pool, RegistrationKey $registration, SeatAct $act, Context $context): void
    {
        $this->claim($pool, $registration, $act, $context, false, true);
    }

    /**
     * Takes a seat when one is free, for an admission or a restore into a pool that may be full: a full pool
     * gives no seat and no error.
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

    /** Frees every seat the registration holds: withdrawal and removal. */
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
