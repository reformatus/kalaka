<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/**
 * A registration's latest payment request that is not cancelled: its state, since when (UTC), and the
 * provider's ID of the request, the ref its paid report carries (`PaidRequest::$requestRef`).
 */
final readonly class OpenPayment
{
    public function __construct(
        public OpenPaymentState $state,
        public \DateTimeImmutable $since,
        public string $requestRef,
    ) {
    }
}
