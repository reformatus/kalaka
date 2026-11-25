<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Payments\RecordOnlinePayment;
use Kalaka\Payments\RecordOnlineRefund;

/**
 * What a payment provider reports back: a paid request and a refund. Each is one system run that writes one
 * ledger line per item, once (money.md, "Payments"). Recording is never gated: it does not look at the
 * registration's status, the event's state or the amount against the balance.
 */
final class OnlinePayments
{
    /**
     * @throws \Kalaka\Foundation\Api\Error\KalakaException a replay that differs from the stored line is a
     *                                                      `SystemFailure`, never a second line
     */
    public function recordPaid(PaidRequest $paid): void
    {
        Runner::current()->run(
            new RecordOnlinePayment(),
            RecordOnlinePayment::inputOf($paid),
            new SystemActor()
        );
    }

    /** @throws \Kalaka\Foundation\Api\Error\KalakaException as `recordPaid()` */
    public function recordRefunded(RefundedRequest $refund): void
    {
        Runner::current()->run(
            new RecordOnlineRefund(),
            RecordOnlineRefund::inputOf($refund),
            new SystemActor()
        );
    }
}
