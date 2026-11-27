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
     * Whether the registration is live: `registered` only. Waitlisted, withdrawn and removed are not live
     * (owner 2026-11-27, walkthrough 7.1: "there should be one abstraction for active registrations, and
     * waitlisted are not in that"; named "live" so as not to clash with the registrant's "active registration").
     * The one test of a live registration: code that means "live" (a count, a
     * seat held, a price, a payment) calls this and never compares with `Registered`; code that means the status
     * itself (a transition's target, `registered_at`, a status's word) names `Registered`.
     * [UNAPPROVED N1163]
     */
    public function isLive(): bool
    {
        return $this === self::Registered;
    }

    /**
     * The stored values of the live statuses: the one list SQL filters on, `isLive()` as strings.
     *
     * @return list<string>
     */
    public static function liveValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isLive()))
        );
    }

    /**
     * Whether the registrant's page shows their details (the money table and the rest): true unless the
     * registration is waitlisted, who see only that they are waitlisted (owner ruling 2026-11-25). The one read of
     * what a waitlisted registrant sees; not an action gate: additional forms and payments stay on
     * `isLive()`.
     */
    public function showsDetails(): bool
    {
        return $this !== self::Waitlisted;
    }
}
