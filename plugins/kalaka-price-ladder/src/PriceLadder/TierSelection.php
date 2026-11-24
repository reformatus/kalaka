<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\PriceLadder\Api\Tier;
use Kalaka\Pricing\Api\FeeRefusal;

/**
 * Which tier of the ladder applies to a registration: the one it chose in the declared field `price_tier`
 * (the owner's ruling 2026-11-19). A tier has no condition and no deadline, so nothing else decides, and a
 * tier is never swapped for another. A chosen tier that is no longer in the ladder, or no `price_tier`
 * answer at all (e.g. after a staff write), refuses (`FeeRefusal`): a base of zero never passes silently.
 * The registrant gets no text of the ladder's own: such a refusal is not reachable from the form, because
 * the slot's `price_tier` is required and offers the ladder's tiers only [UNAPPROVED N18].
 */
final class TierSelection
{
    /** Staff, when the chosen tier is no longer in the ladder (an admission, a restore; #200 T3, approved). */
    public const ADMIN_CHOSEN_TEXT = 'A jelentkező választott díjkategóriája már nem érvényes. Előbb állíts be neki'
        . ' érvényes díjkategóriát.';

    /** Staff, when the registration has no tier answer (#200 T4, approved). */
    public const ADMIN_NONE_TEXT = 'A jelentkezőre egyik díjkategória sem érvényes. Ellenőrizd a díjkategóriák'
        . ' beállításait vagy a jelentkező válaszait.';

    /**
     * @param list<Tier> $tiers in ladder order, at least one
     * @param ?string $chosen the tier key the registration chose (`price_tier`), null when it has no answer
     * @throws FeeRefusal when the chosen tier is not in the ladder, or there is no answer
     */
    public function select(array $tiers, ?string $chosen): Tier
    {
        foreach ($tiers as $tier) {
            if ($tier->key === $chosen) {
                return $tier;
            }
        }
        $refusal = $chosen === null
            ? new FeeRefusal(self::ADMIN_NONE_TEXT, null, 'no_tier_chosen')
            : new FeeRefusal(self::ADMIN_CHOSEN_TEXT, null, 'chosen_tier_not_in_ladder');
        throw $refusal;
    }
}
