<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** A provider's report that a payment request was paid: one item per registration line. */
final readonly class PaidRequest
{
    /**
     * @param string $providerId the provider's `id()`
     * @param string $requestRef the provider's ID of the request (a WooCommerce order ID), `[a-z0-9_.-]`, at most 40
     * @param \DateTimeImmutable $paidAt when the request was paid, the same on every report of it
     * @param list<OnlineItem> $items at least one, each the amount the item actually charged
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $providerId,
        public string $requestRef,
        public \DateTimeImmutable $paidAt,
        public array $items,
    ) {
        OnlineRefs::check($providerId, $requestRef, $items);
    }
}
