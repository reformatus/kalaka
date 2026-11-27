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
     * Whether the registration is active: `registered` only. Waitlisted, withdrawn and removed are inactive
     * (owner 2026-11-27, walkthrough 7.1: "there should be one abstraction for active registrations, and
     * waitlisted are not in that"). The one test of an active registration: code that means "active" (a count, a
     * seat held, a price, a payment) calls this and never compares with `Registered`; code that means the status
     * itself (a transition's target, `registered_at`, a status's word) names `Registered`.
     * [UNAPPROVED N1163]
     */
    public function isActive(): bool
    {
        return $this === self::Registered;
    }

    /**
     * The stored values of the active statuses: the one list SQL filters on, `isActive()` as strings.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isActive()))
        );
    }

    /**
     * Whether the registrant's page shows their details (the money table and the rest): true unless the
     * registration is waitlisted, who see only that they are waitlisted (owner ruling 2026-11-25). The one read of
     * what a waitlisted registrant sees; not an action gate: additional forms and payments stay on
     * `isActive()`.
     */
    public function showsDetails(): bool
    {
        return $this !== self::Waitlisted;
    }
}
