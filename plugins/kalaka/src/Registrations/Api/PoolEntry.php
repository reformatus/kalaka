<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * One pool a `SeatClaimSource` can claim: the field a choice is made in, the option value of the choice and its
 * pool, limited or not. [UNAPPROVED N126]
 */
final readonly class PoolEntry
{
    public function __construct(public string $fieldKey, public string $optionValue, public SeatPool $pool)
    {
    }
}
