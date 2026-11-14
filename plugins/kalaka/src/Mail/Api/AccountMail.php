<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * The two account mails (S15; mail.md, "Configuration"): system mails outside the action-mail catalogue,
 * with built-in content, no body, no layout and no switch. The value is the mail's key in the delivery
 * queue. The submit queues them (`Registrations\Account\AccountOutcome`); `Mail\AccountMailComposer`
 * composes them.
 * // [UNAPPROVED N7] the two mail keys.
 */
enum AccountMail: string
{
    /** The set-password mail: its link sets the new account's password, which activates it. */
    case SetPassword = 'account_set_password';

    /** The attach mail: its link attaches the registration to the account once its holder logs in. */
    case Attach = 'account_attach';
}
