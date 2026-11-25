<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/** registrations-and-identity.md, "Status, capacity and waitlist": the stored status of a registration. */
enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Waitlisted = 'waitlisted';
    case Withdrawn = 'withdrawn';
    case Removed = 'removed';

    /**
     * The status's word where a document shows `registration.status` (a mail; the after-submit section once
     * the words are approved, which keeps its text empty until then): one place for both.
     */
    public function word(): string
    {
        return match ($this) {
            self::Registered => 'rögzített',
            self::Waitlisted => 'várólistás',
            self::Withdrawn => 'visszavont',
            self::Removed => 'törölt',
        };
    }

    /**
     * Whether the registrant's page shows their details (the money table and the rest): true unless the
     * registration is waitlisted, who see only that they are waitlisted (owner ruling 2026-11-25). The one read of
     * what a waitlisted registrant sees; not an action gate: additional forms and payments stay on
     * `=== Registered`. [UNAPPROVED N653]
     */
    public function showsDetails(): bool
    {
        return $this !== self::Waitlisted;
    }
}
