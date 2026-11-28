<?php

declare(strict_types=1);

namespace Kalaka\Extras\Api;

use Kalaka\Foundation\Api\Money\Money;

/**
 * One extra charge of an event (money.md, "Core money scope"): its key, the label organizers gave it and its
 * amount in the event's currency. The participant field whose key equals the charge's key calls it, and the
 * amount is multiplied by the answer's whole value: a consent's `1` charges it once, and a number answer of
 * a positive whole `n` charges it `n` times. It has no condition of its own, and a key no field uses charges
 * nobody. It is its own ledger line, `extras:<key>`. The charge declares that field itself (`extension` source,
 * form-writable, the charge's label as its title) when the charges are saved: a consent or a number field by
 * `type`, consent when none is given; a form field joins it by taking its key and never creates it.
 */
final readonly class Charge
{
    /** The charge's participant field is a consent: ticked once, the amount counts once. */
    public const CONSENT = 'consent';

    /** The charge's participant field is a number of whole units: the amount counts that many times. */
    public const NUMBER = 'number';

    /** @param string $type `consent` or `number`: the type of the participant field the charge declares */
    public function __construct(
        public string $key,
        public string $label,
        public Money $amount,
        public string $type = self::CONSENT,
    ) {
    }
}
