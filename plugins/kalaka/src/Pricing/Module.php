<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Admin\Api\Abilities;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Pricing\Api\ManualConfirmations;

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

    /**
     * money.md, "Confirmation": the manual confirmation flag, a fact, one row per registration that ever had
     * one. `set_at` and `revoked_at` are UTC; an unconfirm revokes the flag and keeps its row with its revoke
     * time, and a new set replaces it. The reason is free text, here where an erase can reach it. [UNAPPROVED N6]
     */
    private const MANUAL_CONFIRMATIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_manual_confirmations (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  set_at datetime(6) NOT NULL,
  set_by bigint(20) unsigned DEFAULT NULL,
  reason varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  revoked_at datetime(6) DEFAULT NULL,
  PRIMARY KEY  (event_id,code)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * The owner's ruling 2026-11-19: staff's latest unconfirm of a registration, a fact, one row per
     * registration ever unconfirmed (an earlier one is in the audit log). `unconfirmed_at` is UTC; the reason
     * is free text, here where an erase can reach it. [UNAPPROVED N12]
     */
    private const UNCONFIRMATIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_unconfirmations (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  unconfirmed_at datetime(6) NOT NULL,
  unconfirmed_by bigint(20) unsigned DEFAULT NULL,
  reason varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (event_id,code)
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
        return new Schema(3, [self::FIXED_PRICES, self::MANUAL_CONFIRMATIONS, self::UNCONFIRMATIONS]);
    }

    /** The per-event actions grants may carry (foundation.md, "Views and grants"). [UNAPPROVED N6] */
    public function capabilities(): array
    {
        return [ManualConfirmations::CONFIRM_MANUALLY];
    }

    public function boot(): void
    {
        // The labels and descriptions of the abilities. [UNAPPROVED T4]
        Abilities::register(
            new SetManualConfirmation(),
            'Visszaigazolás befizetés nélkül',
            'Egy jelentkező visszaigazolása befizetés nélkül, indoklással.'
        );
        Abilities::register(
            new Unconfirm(),
            'Visszaigazolás visszavonása',
            'Egy jelentkező visszaigazolásának visszavonása indoklással, '
                . 'a befizetés nélküli visszaigazolást is beleértve.'
        );
    }
}
