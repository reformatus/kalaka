<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * A choice of the submitted form that takes a seat: the field the registrant chose in and the pool of the
 * choice. The submit takes the seats of its claims when the registration becomes `registered`
 * (`SubmitRegistration`); a waitlisted submit holds none.
 */
final readonly class SeatClaim
{
    public function __construct(public string $fieldKey, public SeatPool $pool)
    {
    }
}
