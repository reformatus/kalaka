<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** A provider's report that part of a paid request was refunded: the refund's items, in the amounts given back. */
final readonly class RefundedRequest
{
    /**
     * @param string $providerId the provider's `id()`
     * @param string $requestRef the provider's ID of the paid request
     * @param string $refundRef the provider's ID of the refund, `[a-z0-9_.-]`, at most 40
     * @param \DateTimeImmutable $refundedAt when the refund was made, the same on every report of it
     * @param list<OnlineItem> $items at least one, the amount given back for each item
     * @param bool $allocated whether the provider split a refund that named no item over the items (it is
     *                        written on the audit entry, for staff)
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $providerId,
        public string $requestRef,
        public string $refundRef,
        public \DateTimeImmutable $refundedAt,
        public array $items,
        public bool $allocated = false,
    ) {
        OnlineRefs::check($providerId, $requestRef, $items);
        OnlineRefs::checkRef($refundRef);
    }
}
