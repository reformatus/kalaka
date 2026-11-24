<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** A registration's latest payment request that is not cancelled: its state and since when (UTC). */
final readonly class OpenPayment
{
    public function __construct(public OpenPaymentState $state, public \DateTimeImmutable $since)
    {
    }
}
