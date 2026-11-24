<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Ledger\Api\LineKind;
use Kalaka\Payments\Api\RefundedRequest;

/**
 * Records a refund of a paid request: one positive `refund` line per item, source `<provider>_order` (the
 * request), key `online:<provider>:refund:<refund>:<item>`, `paidAt` the refund's. Run as the system by
 * `OnlinePayments`. Returns how many lines it wrote. A refund never unconfirms: confirmation reads payments
 * only through its own rule.
 */
final class RecordOnlineRefund extends RecordOnline
{
    public function __construct(private readonly RefundedRequest $refund)
    {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'kalaka/record-online-refund';
    }

    protected function providerId(): string
    {
        return $this->refund->providerId;
    }

    protected function requestRef(): string
    {
        return $this->refund->requestRef;
    }

    protected function items(): array
    {
        return $this->refund->items;
    }

    protected function kind(): LineKind
    {
        return LineKind::Refund;
    }

    protected function keyPart(): string
    {
        return 'refund:' . $this->refund->refundRef;
    }

    protected function allocated(): bool
    {
        return $this->refund->allocated;
    }

    protected function at(): \DateTimeImmutable
    {
        return $this->refund->refundedAt;
    }
}
