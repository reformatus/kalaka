<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

/** Where a registration's latest payment request stands, as its provider reports it (cancelled and none are no state). */
enum OpenPaymentState: string
{
    /** Created and not yet paid: the registrant can still pay it. */
    case Pending = 'pending';

    /** The payment was tried and failed: the request can be paid again. */
    case Failed = 'failed';

    /** Paid at the gateway and not yet complete at the provider. */
    case Processing = 'processing';

    /** Paid. */
    case Paid = 'paid';
}
