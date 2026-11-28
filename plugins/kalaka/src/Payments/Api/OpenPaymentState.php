<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/**
 * Where a registration's latest payment request stands, as its provider reports it (cancelled and none are no state).
 * `Pending` and `Failed` are never paid: a new start replaces such a request (the provider cancels it). Every other
 * state is an earlier card payment that is still open, and a new start is refused as `CardPaymentInFlight`.
 */
enum OpenPaymentState: string
{
    /** Created and not yet paid: the registrant can still pay it. */
    case Pending = 'pending';

    /** The payment was tried and failed (a declined card): never paid, so a new start replaces it. */
    case Failed = 'failed';

    /**
     * Open at the provider in a way core cannot settle: authorised and not yet paid, or in a state the provider
     * does not handle itself (WooCommerce answers it for an order on hold or in any status it does not know).
     * A provider that is unsure answers this one, so the start is refused and nothing is cancelled.
     */
    case Processing = 'processing';

    /**
     * Paid, and its report has not been recorded yet. Once the provider has reported it and core has recorded it,
     * `latestOf()` no longer answers it (null, or a newer request); core also looks at the ledger before it
     * treats a paid request as in flight, so a recorded one never blocks a new start.
     */
    case Paid = 'paid';
}
