<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** The base of payment providers: new methods of `PaymentProvider` get their default here. */
abstract class AbstractPaymentProvider implements PaymentProvider
{
    abstract public function id(): string;

    abstract public function accepts(PaymentProviderInput $input): bool;

    abstract public function payUrl(PaymentProviderInput $input): string;

    /** No request, unless the provider keeps its requests and says so. */
    public function latestOf(PaymentProviderInput $input): ?OpenPayment
    {
        return null;
    }
}
