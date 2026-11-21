<?php

declare(strict_types=1);

namespace Kalaka\Extras;

use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

/**
 * Extra charges and the donation (money.md, "Core money scope", topic 7): two fee components at the extras
 * stage. An event's extra charges are a key, a label, an amount and the participant field whose answer
 * calls it; the donation is the event's whole-number field, as its own `donation` line. Core now, not a
 * plugin.
 */
final class Module extends AbstractModule
{
    /**
     * An event's extra charges, one row per charge, keyed by (event ID, charge key); `position` is the
     * order, `field_key` the participant field whose answer calls it. The amounts are read as they are
     * now: there is no tariff snapshot.
     */
    private const CHARGES = <<<'SQL'
CREATE TABLE {prefix}kalaka_extras_charges (
  event_id bigint(20) unsigned NOT NULL,
  charge_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  position int(10) unsigned NOT NULL,
  label varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id,charge_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** The event's donation setting: the participant field (a whole-number field) whose value is charged. */
    private const DONATIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_extras_donations (
  event_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'extras';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(2, [self::CHARGES, self::DONATIONS]);
    }

    public function contribute(Contributions $to): void
    {
        $to->feeComponent(new ExtrasComponent());
        $to->feeComponent(new DonationComponent());
    }

    public function boot(): void
    {
    }
}
