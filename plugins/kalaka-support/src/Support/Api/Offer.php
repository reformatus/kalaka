<?php

declare(strict_types=1);

namespace Kalaka\Support\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One support offer of an event (money.md, "Sponsor and church support"): its key, the label the organizers gave
 * it, the directory node that grants it (by external ID), its amount in the event's currency, whether it must be
 * requested (answered by staff or a partner before it counts) and its limit of people, if any. The participant
 * field with the offer's key, a consent the form shows, selects it. Who sees it is set on the form, not here.
 * Each applying offer is its own ledger line, `support:<key>`.
 */
final readonly class Offer
{
    public function __construct(
        public string $key,
        public string $label,
        public string $node,
        public Money $amount,
        public bool $needsRequest,
        public ?int $limit,
    ) {
    }
}
