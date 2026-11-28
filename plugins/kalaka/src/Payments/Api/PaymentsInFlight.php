<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Ledger\Api\Ledger;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The one rule of whether a registration's latest payment request is money on its way, so no new request starts:
 * it is processing (authorised, or open in a state the provider does not handle), or paid and not yet
 * recorded. A paid request whose lines the ledger already holds
 * (key prefix `online:<provider>:order:<request>:`) is recorded, so it is not in flight. Read by starting a card
 * payment and by the status read's `can`.
 */
final class PaymentsInFlight
{
    public function __construct(private readonly Ledger $ledger = new Ledger())
    {
    }

    public function is(PaymentProvider $provider, RegistrationKey $key, ?OpenPayment $latest): bool
    {
        if ($latest === null) {
            return false;
        }

        return $latest->state === OpenPaymentState::Processing
            || ($latest->state === OpenPaymentState::Paid && !$this->ledger->holdsLineKeyStartingWith(
                $key,
                'online:' . $provider->id() . ':order:' . $latest->requestRef . ':'
            ));
    }
}
