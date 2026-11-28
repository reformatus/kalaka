<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Core money scope": an event's price ladder, one row per tier, keyed by (event ID, tier key);
     * `position` is the ladder order. A tier has no condition and no deadline: it is chosen through the declared
     * field `price_tier` only. The amounts are read as they are now: there is no tariff snapshot.
     * `seat_limit` is the tier's optional limit of people (null: none): the limit of its seat pool `tier.<key>`.
     */
    private const TIERS = <<<'SQL'
CREATE TABLE {prefix}kalaka_price_ladder_tiers (
  event_id bigint(20) unsigned NOT NULL,
  tier_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  position int(10) unsigned NOT NULL,
  label varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  seat_limit int(10) unsigned DEFAULT NULL,
  PRIMARY KEY  (event_id,tier_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'price_ladder';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function schema(): Schema
    {
        return new Schema(2, [self::TIERS]);
    }

    public function contribute(Contributions $to): void
    {
        $to->feeComponent(new PriceLadderComponent());
        $to->slotRequirement(new LadderSlotRequirement());
        $to->seatClaimSource(new TierClaims());
    }

    public function boot(): void
    {
    }
}
