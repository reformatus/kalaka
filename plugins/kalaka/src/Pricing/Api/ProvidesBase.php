<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/**
 * A fee component that provides an event's base price (money.md, "Core money scope": the price ladder). Core's
 * fixed price is the base only when no component of the event provides it, whatever that component owes a
 * given registration: an event with a ladder has no fixed-price base. A plain read of the component's own
 * settings; it writes nothing.
 */
interface ProvidesBase extends FeeComponent
{
    public function providesBase(int $eventId): bool;
}
