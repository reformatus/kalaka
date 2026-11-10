<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/** Where a delivery stands: mail.md, "Words", "Sending" and "Failures". */
enum DeliveryStatus: string
{
    /** Waiting for the sender; may carry the error of an earlier attempt. */
    case Pending = 'pending';

    /** Claimed: the sender is calling `wp_mail`, or crashed while it did. */
    case Sending = 'sending';

    case Sent = 'sent';

    /** Never attempted: the delivery has no valid address. */
    case Failed = 'failed';
}
