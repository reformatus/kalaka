<?php

declare(strict_types=1);

namespace Kalaka\Portal\Api;

use Kalaka\Payments\Api\OpenPaymentState;

/** The registration's latest card payment request, as its provider reports it: its state and since when (UTC). */
final readonly class CardPaymentView
{
    public function __construct(public OpenPaymentState $state, public \DateTimeImmutable $since)
    {
    }
}
