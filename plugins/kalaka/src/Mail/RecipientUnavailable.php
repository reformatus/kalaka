<?php

declare(strict_types=1);

namespace Kalaka\Mail;

/**
 * Thrown by `MailComposer::recipient()` when the address cannot be determined at all, as opposed to a
 * registration that has none: the sender marks the delivery failed with this reason instead of
 * `invalid_address`, with no attempt (mail.md, "Sending": a delivery without a valid address).
 * // [UNAPPROVED N22] a distinct failure reason when the address cannot be determined.
 */
final class RecipientUnavailable extends \RuntimeException
{
    /** The registration (or its first submission) is gone. */
    public const NO_REGISTRATION = 'no_registration';

    /** The registration's slot form is unpublished or has a definition problem. */
    public const NO_RECIPIENT_FORM = 'no_recipient_form';

    /** @param string $reason one of the constants: a machine code, never a value */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
