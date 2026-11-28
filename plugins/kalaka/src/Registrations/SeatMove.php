<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Registrations\Api\SeatClaim;

/**
 * What an additional form's submit does to the seats of a registration: the pools its values after the submit claim
 * that its values now do not (taken), and the pools its values now claim that they no longer would (freed).
 */
final readonly class SeatMove
{
    /**
     * @param list<SeatClaim> $takes
     * @param list<SeatClaim> $frees
     */
    public function __construct(public array $takes = [], public array $frees = [])
    {
    }

    public function isEmpty(): bool
    {
        return $this->takes === [] && $this->frees === [];
    }
}
