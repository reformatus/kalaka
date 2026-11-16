<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

use Kalaka\Mail\Api\AccountMail;

/**
 * What a submit does about accounts (registrations-and-identity.md, "Accounts on the registration form"),
 * decided before the transaction (`RegistrationSubmit`) and written by `SubmitRegistration` only for a new
 * registration, never for a repeat:
 *
 * - the owner account the registration gets: the logged-in person; null for none (mode off, or someone not
 *   logged in in `optional` mode);
 * - the set-password mail to that account, while it is one Kaláka created whose password was never set
 *   (`kalaka_unverified`); null for none.
 */
final readonly class AccountOutcome
{
    private function __construct(
        public ?int $ownerUserId,
        public ?AccountMail $mail,
        public ?int $mailUserId,
    ) {
    }

    /** No account: mode off, or someone not logged in in `optional` mode. */
    public static function none(): self
    {
        return new self(null, null, null);
    }

    /** The logged-in person owns the registration; an unverified account also gets the set-password mail. */
    public static function loggedIn(int $userId, bool $unverified = false): self
    {
        return $unverified
            ? new self($userId, AccountMail::SetPassword, $userId)
            : new self($userId, null, null);
    }
}
