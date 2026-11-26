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
            // [UNAPPROVED T976] Before: 'visszavont'.
            self::Withdrawn => 'lemondott',
            self::Removed => 'törölt',
        };
    }
}
