<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Cli\EventSeeder;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * events-and-forms.md, "Events". Points are UTC `datetime(6)` columns; the custom points are
     * JSON in `points`, a `longtext` for the same reason as the audit's JSON columns. `currency` is the
     * event's `Currency` code. The payment settings (S16/S24, SB-1, SB-2; S21's N): the bank-transfer
     * beneficiary and account number as entered, the transfer-reference prefix put before the reference
     * code, and the payment-due N in days; each null until set.
     */
    private const EVENTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version int(10) unsigned NOT NULL,
  name varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  state varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  capacity int(10) unsigned DEFAULT NULL,
  is_full tinyint(1) NOT NULL DEFAULT 0,
  registration_opens_at datetime(6) DEFAULT NULL,
  registration_closes_at datetime(6) DEFAULT NULL,
  starts_at datetime(6) DEFAULT NULL,
  ends_at datetime(6) DEFAULT NULL,
  points longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  next_point_id int(10) unsigned NOT NULL DEFAULT 1,
  archived_at datetime(6) DEFAULT NULL,
  transfer_beneficiary varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  transfer_account varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  transfer_reference_prefix varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  payment_due_days smallint(5) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY state (state)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'events';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(3, [self::EVENTS]);
    }

    public function contribute(Contributions $to): void
    {
        $to->seeder(new EventSeeder());
    }

    public function boot(): void
    {
    }
}
