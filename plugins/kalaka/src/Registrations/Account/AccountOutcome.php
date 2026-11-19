<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Account;

/**
 * What a submit does about accounts (registrations-and-identity.md, "Accounts on the registration form"),
 * decided before the transaction (`RegistrationSubmit`) and written by `SubmitRegistration` only for a new
 * registration, never for a repeat: the owner account the registration gets, the logged-in person; null for
 * none (mode off, or someone not logged in in `optional` mode).
 */
final readonly class AccountOutcome
{
    private function __construct(public ?int $ownerUserId)
    {
    }

    /** No account: mode off, or someone not logged in in `optional` mode. */
    public static function none(): self
    {
        return new self(null);
    }

    /** The logged-in person owns the registration. */
    public static function loggedIn(int $userId): self
    {
        return new self($userId);
    }
}
