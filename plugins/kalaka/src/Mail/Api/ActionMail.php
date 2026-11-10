<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/**
 * The action mails defined in code, in catalogue order (mail.md, "Configuration"). The value is the mail's
 * key in the delivery queue; per event each is switched on or off and has its own body.
 * // [UNAPPROVED N16] the six actions of the catalogue and their keys.
 */
enum ActionMail: string
{
    case Registered = 'registered';
    case Waitlisted = 'waitlisted';
    case Admitted = 'admitted';
    case PaymentRecorded = 'payment_recorded';
    case Confirmed = 'confirmed';
    case LinkRegenerated = 'link_regenerated';

    /** The catalogue's name of the mail: what causes it. */
    public function label(): string
    {
        // [UNAPPROVED N20] the six catalogue labels.
        return match ($this) {
            self::Registered => 'Jelentkezés rögzítve',
            self::Waitlisted => 'Jelentkezés várólistára helyezve',
            self::Admitted => 'Jelentkezés felvéve a várólistáról',
            self::PaymentRecorded => 'Befizetés rögzítve',
            self::Confirmed => 'Jelentkezés véglegesítve',
            self::LinkRegenerated => 'Személyes link lecserélve',
        };
    }
}
