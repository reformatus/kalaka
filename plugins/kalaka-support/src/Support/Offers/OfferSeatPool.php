<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\SeatPool;

/**
 * The places of one support offer under the one seat rule: the pool row is the offer's own row, its limit the
 * offer's limit of people, if any (every offer has a pool, limited or not). Its key is `offer.<offer key>`. The
 * row is at the quota lock level, so a submit locks and checks it before it writes the registration.
 * [UNAPPROVED N96]
 */
final class OfferSeatPool implements SeatPool
{
    public function __construct(
        private readonly int $eventId,
        private readonly string $offerKey,
        private readonly OfferRepository $offers = new OfferRepository(),
    ) {
    }

    public function eventId(): int
    {
        return $this->eventId;
    }

    public function key(): string
    {
        return 'offer.' . $this->offerKey;
    }

    public function lockLevel(): LockLevel
    {
        return LockLevel::Quota;
    }

    public function lockRow(): array
    {
        return ['kalaka_support_offers', [$this->eventId, $this->offerKey]];
    }

    public function label(): string
    {
        return ($this->offers->find($this->row()) ?? throw new NotFound())->label;
    }

    public function limit(): ?int
    {
        return ($this->offers->find($this->row()) ?? throw new NotFound())->limit;
    }

    public function lock(Context $context): ?int
    {
        return ($this->offers->findForUpdate($this->row(), $context) ?? throw new NotFound())->limit;
    }

    /** @return array{eventId: int, key: string} */
    private function row(): array
    {
        return ['eventId' => $this->eventId, 'key' => $this->offerKey];
    }
}
