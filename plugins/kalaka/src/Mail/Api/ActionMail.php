<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * The action mails defined in code, in catalogue order (mail.md, "Configuration"). The value is the mail's
 * key in the delivery queue; per event each is switched on or off and has its own body.
 * Admission from the waitlist has no mail of its own: it sends "registered" (owner 2026-11-16), and a body
 * tells the two apart with `registration.from_waitlist`. A stored row of the former `admitted` is skipped on
 * read (`ActionMailRepository::ofEvent()`).
 */
enum ActionMail: string
{
    case Registered = 'registered';
    case Waitlisted = 'waitlisted';
    case PaymentRecorded = 'payment_recorded';
    case Confirmed = 'confirmed';

    /**
     * Whether an act on this line queues this mail: the one declared list of the points that work (owner
     * 2026-11-27, walkthrough 8.5; the screen shows only those as usable). A point that is not wired can be set
     * up and switched on, but nothing sends it yet. A point whose producer another slice brings is not here: the
     * "payment recorded" mail is queued by the payment-recorded mail's own slice (#233), and the "answered" mail
     * of the walkthrough's list does not exist on this branch. The PR that adds a producer adds its point here.
     */
    public function isWired(): bool
    {
        return match ($this) {
            self::Registered, self::Waitlisted, self::Confirmed => true,
            self::PaymentRecorded => false,
        };
    }

    /** The catalogue's name of the mail: what causes it. */
    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Jelentkezés rögzítve',
            self::Waitlisted => 'Jelentkezés várólistára helyezve',
            self::PaymentRecorded => 'Befizetés rögzítve',
            self::Confirmed => 'Jelentkezés véglegesítve',
        };
    }
}
