<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Core money scope": the event's current fixed price, one row per event that has one;
     * Pricing's own configuration, not a column of `kalaka_events`. There is no tariff snapshot: every
     * evaluation reads this row as it is now.
     */
    private const FIXED_PRICES = <<<'SQL'
CREATE TABLE {prefix}kalaka_fixed_prices (
  event_id bigint(20) unsigned NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'pricing';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::FIXED_PRICES]);
    }

    public function boot(): void
    {
    }
}
