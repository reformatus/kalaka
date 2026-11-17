<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\Facts;
use Kalaka\PriceLadder\Api\Tier;

/**
 * Which tier of the ladder applies to a registration: the first, in ladder order, whose deadline lets in
 * its `registered_at` and whose availability condition holds over its facts, through the one availability
 * base (S76). The registrant reaches a tier through ordinary answers (the support level, …), which the
 * conditions read. [UNAPPROVED N4]
 */
final class TierSelection
{
    public function __construct(private readonly Availability $availability = new Availability())
    {
    }

    /**
     * @param list<Tier> $tiers in ladder order
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure an invalid condition or a key the facts do not declare
     */
    public function select(array $tiers, Facts $facts, ?\DateTimeImmutable $registeredAt): ?Tier
    {
        $open = array_values(array_filter($tiers, static fn (Tier $tier): bool => $tier->openAt($registeredAt)));
        $available = $this->availability->available($open, $facts);

        return $available === [] ? null : $available[array_key_first($available)];
    }
}
