<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * What a staff correction of one field value does to the registration's seats, planned from a plain read
 * (`FieldCorrections::plan()`): the seats the new value claims and the old one did not (`take`), those the old value
 * claimed and the new one does not (`free`), each pool once, and a fingerprint of the registration's stored values
 * as the plan saw them. The values themselves are never part of the plan: the correction asks
 * `FieldCorrections::check()` under the lock whether they are still the same.
 */
final readonly class FieldCorrectionPlan
{
    /**
     * @param list<SeatClaim> $take
     * @param list<SeatClaim> $free
     */
    public function __construct(public string $fingerprint, public array $take, public array $free)
    {
    }
}
