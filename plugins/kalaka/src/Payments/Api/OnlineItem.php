<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Ledger\Api\Payer;
use Kalaka\Registrations\Api\RegistrationKey;

/** One item of a paid or refunded request: what one payer paid or got back for one registration. */
final readonly class OnlineItem
{
    /**
     * @param Money $amount above zero, in either direction
     * @param string $itemRef the provider's ID of the item within its request, `[a-z0-9_.-]`, at most 40
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public RegistrationKey $registration,
        public string $payer,
        public Money $amount,
        public string $itemRef,
    ) {
        if (
            !Payer::isValid($payer) || !$amount->isPositive()
            || preg_match(OnlineRefs::REF_PATTERN, $itemRef) !== 1
        ) {
            throw new \InvalidArgumentException('An online item has a payer, a positive amount and an item ref.');
        }
    }
}
