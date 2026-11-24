<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

/**
 * One pool of seats: a limited number of places that registrations hold under the one seat rule
 * (foundation.md, "Facts, decisions and computed values"). The event's places, a set option's places, a price
 * tier's places (kalaka-price-ladder) and a support offer's places (kalaka-support) are pools. The pool's own
 * row is what `Seats` locks `FOR UPDATE` before it counts, so the module that owns the row implements this
 * interface; the limit is that row's setting, never a seat.
 */
interface SeatPool
{
    /** The pool of the event's own places: its seats are the event's counted `registered` registrations. */
    public const EVENT = 'event';

    public function eventId(): int;

    /**
     * The stored key of the pool, unique within its event: `event`, or `<kind>.<identifier>` such as
     * `option.<set ID>.<option key>`.
     */
    public function key(): string;

    /**
     * The lock level of the pool's own row (foundation.md, "Lock order"): the event row, a quota row, or
     * `Other` for any other. A pool before the registration row is locked and checked before the
     * registration is written; any other once it is.
     */
    public function lockLevel(): LockLevel;

    /**
     * The row `lock()` reads `FOR UPDATE`, as the runner's lock guard sees it: the table name without the
     * WordPress prefix and the key columns in key order. An act that claims several pools sorts them by it
     * (`Seats::inLockOrder()`), so it takes their rows in the order the guard accepts.
     *
     * @return array{0: string, 1: list<int|string>}
     */
    public function lockRow(): array;

    /** The pool's limit by a plain read, without a lock; null when it has none. */
    public function limit(): ?int;

    /**
     * Reads the pool's own row `FOR UPDATE` in the run and returns its limit; null when it has none. A row
     * the run already holds is no new lock.
     *
     * @throws NotFound when the row is gone
     */
    public function lock(Context $context): ?int;
}
