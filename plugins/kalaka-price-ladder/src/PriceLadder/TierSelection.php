<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Forms\Api\Availability;
use Kalaka\Forms\Api\Facts;
use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\FeeRefusal;

/**
 * Which tier of the ladder applies to a registration. A tier is available when its deadline lets in the
 * registration's `registered_at` and its condition holds over its facts, through the one availability base
 * (S76). The registrant chooses a tier in the declared field `price_tier`: the chosen tier applies when it is
 * available, and is never swapped for another when it is not (the owner's ruling 2026-11-19, (d)). Without a
 * chosen tier (a form that does not hold the field) the first available tier in ladder order applies, so a
 * ladder chosen by ordinary answers keeps working [UNAPPROVED, #200 N4]. A chosen tier that is not available,
 * or no available tier at all, refuses (`FeeRefusal`): a base of zero never passes silently.
 */
final class TierSelection
{
    /** The registrant, when their own submit breaks their chosen tier (#200 T2, approved). */
    public const PORTAL_TEXT = 'Ez a válasz nem felel meg a választott díjkategóriád feltételeinek. Ha mégis ezt'
        . ' szeretnéd megadni, fordulj a szervezőkhöz.';

    /** Staff, when the chosen tier no longer applies (an admission, a restore; #200 T3, approved). */
    public const ADMIN_CHOSEN_TEXT = 'A jelentkező választott díjkategóriája már nem érvényes. Előbb állíts be neki'
        . ' érvényes díjkategóriát.';

    /** Staff, when no tier applies at all (#200 T4, approved). */
    public const ADMIN_NONE_TEXT = 'A jelentkezőre egyik díjkategória sem érvényes. Ellenőrizd a díjkategóriák'
        . ' beállításait vagy a jelentkező válaszait.';

    public function __construct(private readonly Availability $availability = new Availability())
    {
    }

    /**
     * @param list<Tier> $tiers in ladder order, at least one
     * @param ?string $chosen the tier key the registration chose (`price_tier`), null when it chose none
     * @throws FeeRefusal when the chosen tier is not available, or no tier is
     * @throws \Kalaka\Foundation\Api\Error\SystemFailure an invalid condition or a key the facts do not declare
     */
    public function select(array $tiers, Facts $facts, ?\DateTimeImmutable $registeredAt, ?string $chosen): Tier
    {
        $open = array_values(array_filter($tiers, static fn (Tier $tier): bool => $tier->openAt($registeredAt)));
        $available = $this->availability->available($open, $facts);
        if ($chosen !== null && !isset($available[$chosen])) {
            $refusal = new FeeRefusal(self::ADMIN_CHOSEN_TEXT, self::PORTAL_TEXT, 'chosen_tier_unavailable');
            throw $refusal;
        }
        if ($available === []) {
            $refusal = new FeeRefusal(self::ADMIN_NONE_TEXT, self::PORTAL_TEXT, 'no_tier_available');
            throw $refusal;
        }

        return $available[$chosen ?? array_key_first($available)];
    }
}
