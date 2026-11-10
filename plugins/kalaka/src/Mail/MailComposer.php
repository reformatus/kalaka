<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/**
 * The seam between the sender and the rendering of a mail, which renders at send time from current data
 * (mail.md, "Sending"). The mail bodies come in a later slice; until a composer is bound, the sender does
 * not run. The address is asked for before the claim, the rest after it.
 * // [UNAPPROVED N9] the composer seam: `recipient()` before the claim, `compose()` after it.
 */
interface MailComposer
{
    /**
     * The address to send the delivery to, from current data; null when the registration has none. The
     * sender checks it with `is_email()` before the claim.
     */
    public function recipient(Delivery $delivery): ?string;

    /** @throws \Throwable when the mail cannot be rendered: an error after the claim */
    public function compose(Delivery $delivery, string $to): MailMessage;
}
