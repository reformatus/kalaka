<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Clock\WordPressClock;

final class Module extends AbstractModule
{
    /**
     * foundation.md, "Audit". JSON columns are `longtext`: MariaDB reports `json` as `longtext`,
     * so dbDelta's dry run would see a pending change on every boot.
     */
    private const AUDIT_ENTRIES = <<<'SQL'
CREATE TABLE {prefix}kalaka_audit_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned DEFAULT NULL,
  correlation_id varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  occurred_at datetime NOT NULL,
  side varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  actor_type varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  actor_event_id bigint(20) unsigned DEFAULT NULL,
  actor_code varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  action varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  ip_address varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  user_agent varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  reason text CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  outcome varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  error longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  changes longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY parent_id (parent_id),
  KEY correlation_id (correlation_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    private const AUDIT_SUBJECTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_audit_subjects (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  event_id bigint(20) unsigned DEFAULT NULL,
  record_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  record_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (id),
  KEY event_record (event_id,record_type,record_key,entry_id),
  KEY entry_id (entry_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'foundation';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::AUDIT_ENTRIES, self::AUDIT_SUBJECTS]);
    }

    public function boot(): void
    {
        Runner::publish(new Runner(new WordPressClock()));
    }
}
