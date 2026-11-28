<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Support\Api\Offer;

/**
 * A row of `kalaka_support_offers`: one support offer of an event, at its position (0 first). `nodeId` is the
 * granting directory node's row; nodes are never deleted, so it always resolves.
 */
final class OfferRecord
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $key,
        public int $position,
        public string $label,
        public int $nodeId,
        public Money $amount,
        public bool $needsRequest,
        public ?int $limit,
        public int $version = 0,
    ) {
    }

    public function offer(string $nodeExternalId): Offer
    {
        return new Offer($this->key, $this->label, $nodeExternalId, $this->amount, $this->needsRequest, $this->limit);
    }
}
