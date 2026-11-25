<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The payments port (money.md, "Payments"): what core needs from a payment method that collects money online.
 * An outside point (modules-and-extensions.md, "The extension surface"): it runs outside the transaction and
 * returns values. It reports back through `OnlinePayments`.
 */
interface PaymentProvider
{
    /** `[a-z][a-z0-9_]{0,31}`; names the provider in ledger keys and sources, e.g. `woocommerce`. */
    public function id(): string;

    /** Whether the provider can collect in the currency now (a matching currency and a usable gateway). Cheap. */
    public function accepts(Currency $currency): bool;

    /**
     * The address the registrant pays at. Creates the request, or reuses an open one that asks for the same
     * lines, and cancels an open one that no longer matches: the provider's job.
     */
    public function payUrl(PaymentRequest $request, string $returnUrl): string;

    /**
     * The state of the registration's latest request; null when it has none or the latest was cancelled.
     * `Paid` means paid and not yet reported as recorded: once the provider's report of it has been recorded
     * (`OnlinePayments::recordPaid()` returned), the provider answers null or a newer request, never the
     * recorded one as `Paid`. Core still checks the ledger for that request's lines (`OpenPayment::$requestRef`)
     * before it refuses a start for a paid request.
     */
    public function latestOf(RegistrationKey $key): ?OpenPayment;
}
