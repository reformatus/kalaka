<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A fee component that names its own `charge` lines (money.md, "Ledger": a line stores no name, so the admin
 * asks the component). A component that does not implement it has its lines read as `fee`, „Díj”.
 */
interface NamedFeeComponent extends FeeComponent
{
    /**
     * The names of the registration's lines of this component, in one read.
     *
     * @param list<?string> $items the items of the lines asked about; null is the component's own, undivided line
     * @return array<array-key, LineName> by item; the undivided line under the key `''`; an item left out is
     *     read as `fee`
     */
    public function lineNames(RegistrationKey $registration, array $items): array;
}
