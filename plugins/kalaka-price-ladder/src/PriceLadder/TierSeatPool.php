<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\SeatPool;

/**
 * The places of one price tier under the one seat rule: the pool row is the tier's own row, its limit
 * the tier's limit of people (none: unlimited, but every chooser still holds a seat row). Its key is
 * `tier.<tier key>`. The row is at the quota lock level, so a submit locks and checks it before it writes the
 * registration.
 */
final class TierSeatPool implements SeatPool
{
    public function __construct(
        private readonly int $eventId,
        private readonly string $tierKey,
        private readonly TierRepository $tiers = new TierRepository(),
    ) {
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function key(): string
    {
        return 'tier.' . $this->tierKey;
    }

    public function lockLevel(): LockLevel
    {
        return LockLevel::Quota;
    }

    public function lockRow(): array
    {
        return ['kalaka_price_ladder_tiers', [$this->eventId, $this->tierKey]];
    }

    public function limit(): ?int
    {
        return ($this->tiers->find($this->row()) ?? throw new NotFound())->limit;
    }

    public function lock(Context $context): ?int
    {
        return ($this->tiers->findForUpdate($this->row(), $context) ?? throw new NotFound())->limit;
    }

    /** @return array{eventId: int, key: string} */
    private function row(): array
    {
        return ['eventId' => $this->eventId, 'key' => $this->tierKey];
    }
}
