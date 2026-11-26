<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/**
 * The payments port (money.md, "Payments"): what core needs from a payment method that collects money online.
 * An outside point (modules-and-extensions.md, "The extension surface"): it runs outside the transaction and
 * returns values. It reports back through `OnlinePayments`. Every operation takes one `PaymentProviderInput`;
 * `AbstractPaymentProvider` is the base to extend.
 */
interface PaymentProvider
{
    /** `[a-z][a-z0-9_]{0,31}`; names the provider in ledger keys and sources, e.g. `woocommerce`. */
    public function id(): string;

    /**
     * Whether the provider can collect in the input's currency now (a matching currency and a usable gateway).
     * Cheap.
     */
    public function accepts(PaymentProviderInput $input): bool;

    /**
     * The address the registrant pays at. Cancels the registration's open requests that were never paid and
     * creates a new one: the provider's job. It never reuses a request, and it is called only when `latestOf()`
     * found nothing else open (core refuses the start first as `CardPaymentInFlight`).
     */
    public function payUrl(PaymentProviderInput $input): string;

    /**
     * The state of the input's registration's latest request (a lookup); null when it has none or the latest was
     * cancelled.
     * `Paid` means paid and not yet reported as recorded: once the provider's report of it has been recorded
     * (`OnlinePayments::recordPaid()` returned), the provider answers null or a newer request, never the
     * recorded one as `Paid`. Core still checks the ledger for that request's lines (`OpenPayment::$requestRef`)
     * before it refuses a start for a paid request.
     */
    public function latestOf(PaymentProviderInput $input): ?OpenPayment;
}
