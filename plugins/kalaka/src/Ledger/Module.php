<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Ledger": append-only lines, keyed by an ID and read per registration (event first).
     * `amount` is signed, `value_date`, `paid_at` and `recorded_at` UTC `datetime(6)`;
     * `source_*` is a `Subject`. No text column holds free text: lines survive erasure.
     */
    private const LEDGER_LINES = <<<'SQL'
CREATE TABLE {prefix}kalaka_ledger_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  kind varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  payer varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  source_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  source_event_id bigint(20) unsigned DEFAULT NULL,
  source_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  idempotency_key varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  value_date datetime(6) DEFAULT NULL,
  paid_at datetime(6) DEFAULT NULL,
  recorded_at datetime(6) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (event_id,code,idempotency_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'ledger';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(2, [self::LEDGER_LINES]);
    }

    /**
     * The per-event actions grants may carry (foundation.md, "Views and grants").
     * [UNAPPROVED S36-5]
     */
    public function capabilities(): array
    {
        return [
            'kalaka_record_payment',
            'kalaka_record_refund',
        ];
    }

    public function boot(): void
    {
    }
}
