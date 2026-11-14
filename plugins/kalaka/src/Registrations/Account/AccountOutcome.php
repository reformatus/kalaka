<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

use Kalaka\Mail\Api\AccountMail;

/**
 * What a submit does about accounts (registrations-and-identity.md, "Accounts on the registration form"),
 * decided before the transaction (`AccountSignUp`, `RegistrationSubmit`) and written by
 * `SubmitRegistration` only for a new registration, never for a repeat:
 *
 * - the owner account the registration gets: the logged-in person, or the account the sign-up created;
 *   null for none (mode off, skip, or a sign-up with an email that already has an account);
 * - the account mail it queues, an action mail to that account: the set-password mail to the new account,
 *   or the attach mail to the account that already holds the email; null for none.
 */
final readonly class AccountOutcome
{
    private function __construct(
        public ?int $ownerUserId,
        public ?AccountMail $mail,
        public ?int $mailUserId,
    ) {
    }

    /** No account: mode off, or skip. */
    public static function none(): self
    {
        return new self(null, null, null);
    }

    /** The logged-in person owns the registration; no mail. */
    public static function loggedIn(int $userId): self
    {
        return new self($userId, null, null);
    }

    /** The sign-up created the account: it owns the registration and gets the set-password mail. */
    public static function created(int $userId): self
    {
        return new self($userId, AccountMail::SetPassword, $userId);
    }

    /** The sign-up's email already has an account: no owner yet, and the attach mail to that account. */
    public static function existing(int $holderUserId): self
    {
        return new self(null, AccountMail::Attach, $holderUserId);
    }
}
