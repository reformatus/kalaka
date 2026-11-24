<?php

declare(strict_types=1);

namespace Kalaka\Payments;

use Kalaka\Ledger\Api\LineKind;
use Kalaka\Payments\Api\PaidRequest;

/**
 * Records a paid payment request: one negative `payment` line per item, source `<provider>_order`, key
 * `online:<provider>:order:<request>:<item>`, `paidAt` the request's. Run as the system by `OnlinePayments`.
 * Returns how many lines it wrote.
 */
final class RecordOnlinePayment extends RecordOnline
{
    public function __construct(private readonly PaidRequest $paid)
    {
        parent::__construct();
    }

    public static function name(): string
    {
        return 'kalaka/record-online-payment';
    }

    protected function providerId(): string
    {
        return $this->paid->providerId;
    }

    protected function requestRef(): string
    {
        return $this->paid->requestRef;
    }

    protected function items(): array
    {
        return $this->paid->items;
    }

    protected function kind(): LineKind
    {
        return LineKind::Payment;
    }

    protected function keyPart(): string
    {
        return 'order:' . $this->paid->requestRef;
    }

    protected function at(): \DateTimeImmutable
    {
        return $this->paid->paidAt;
    }
}
