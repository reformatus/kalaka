<?php

declare(strict_types=1);

namespace Kalaka\ParticipantFields;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;

/**
 * The event's participant fields: the table, its records, the two use cases that write it and the facade
 * (`Api\ParticipantFields`) everyone else reads and writes through.
 */
final class Module extends AbstractModule
{
    /**
     * The event's participant fields, keyed by (event ID, field key). `field_key` and `type`
     * are compared exactly (`utf8mb4_nopad_bin`); `options` is JSON; times are UTC `datetime(6)`.
     */
    private const PARTICIPANT_FIELDS = <<<'SQL'
CREATE TABLE {prefix}kalaka_participant_fields (
  event_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  type varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  title text CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  options longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  source varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  retired_at datetime(6) DEFAULT NULL,
  created_at datetime(6) NOT NULL,
  updated_at datetime(6) NOT NULL,
  position int(10) unsigned NOT NULL,
  form_writable tinyint(1) NOT NULL,
  PRIMARY KEY  (event_id,field_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'participant_fields';
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::PARTICIPANT_FIELDS]);
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function boot(): void
    {
    }
}
