<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * The account mails (S15; mail.md, "Configuration"): system mails outside the action-mail catalogue,
 * with built-in content, no body, no layout and no switch. The value is the mail's key in the delivery
 * queue, defined only here. The submit queues it (`Registrations\Account\AccountOutcome`);
 * `Mail\AccountMailComposer` composes it.
 * // [UNAPPROVED N7] the mail key.
 */
enum AccountMail: string
{
    /** The set-password mail: its link sets the new account's password, which activates it. */
    case SetPassword = 'account_set_password';
}
