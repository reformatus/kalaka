<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Admin\Api\Abilities;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Ledger\Api\Ledger;

final class Module extends AbstractModule
{
    /**
     * money.md, "Ledger": append-only lines, keyed by an ID and read per registration (event first).
     * `amount` is signed, `value_date`, `paid_at` and `recorded_at` UTC `datetime(6)`;
     * `source_*` is a `Subject`. No text column holds free text: lines survive erasure. `method` is a
     * `PaymentMethod` value on the manual kinds, `correction` marks a manual payment or refund recorded as a
     * correction.
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
  method varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  correction tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (event_id,code,idempotency_key),
  KEY registration_line (event_id,code,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * money.md, "Recording payments by hand": the free text of a manual entry (a bank reference, a payer
     * note, a correction's reason), keyed to its line, in a table erasure can reach. The ledger line holds none.
     */
    private const PAYMENT_NOTES = <<<'SQL'
CREATE TABLE {prefix}kalaka_payment_notes (
  event_id bigint(20) unsigned NOT NULL,
  line_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  note varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (event_id,line_id),
  KEY registration (event_id,code,line_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * money.md, "Recording payments by hand": a manual charge or discount („Díj”, „Kedvezmény”) is a fact, one
     * row per entry, composed by the core `manual` fee component. The amount is typed positive and `type` gives
     * the sign. `title` is free text the registrant sees: erasure sets it to NULL, the only update a row takes.
     * No method, no date, no internal note.
     */
    private const MANUAL_FEES = <<<'SQL'
CREATE TABLE {prefix}kalaka_manual_fees (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  type varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  payer varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  title varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  idempotency_key varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  recorded_by bigint(20) unsigned DEFAULT NULL,
  recorded_at datetime(6) NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (event_id,code,idempotency_key),
  KEY registration (event_id,code,id)
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
        return new Schema(4, [self::LEDGER_LINES, self::PAYMENT_NOTES, self::MANUAL_FEES]);
    }

    /**
     * The per-event actions grants may carry (foundation.md, "Views and grants"): recording money of any kind is
     * one action, `kalaka_record_money`.
     */
    public function capabilities(): array
    {
        return [
            Ledger::RECORD_MONEY,
            Ledger::VIEW_MONEY,
        ];
    }

    public function boot(): void
    {
        // The labels and descriptions of the abilities. [UNAPPROVED T5, T6, T7, T8]
        Abilities::register(
            new RecordPayment(),
            'Befizetés rögzítése',
            'Egy jelentkező befizetésének kézi rögzítése.'
        );
        Abilities::register(
            new RecordRefund(),
            'Visszafizetés rögzítése',
            'Egy jelentkezőnek visszafizetett összeg kézi rögzítése.'
        );
        // [UNAPPROVED T16, T17] drafts for the lektor.
        // the correction's description without the reason, replacing ticked T17.
        Abilities::register(
            new RecordCorrection(),
            'Korrekció rögzítése',
            'Egy jelentkező befizetett összegének kézi növelése vagy csökkentése.'
        );
        // [UNAPPROVED T20, T21] the lektor's wording of 2026-11-21.
        Abilities::register(
            new RecordCharge(),
            'Díj rögzítése',
            'Egy jelentkező fizetendő összegének kézi növelése, megnevezéssel.'
        );
        // [UNAPPROVED T22, T23] the lektor's wording of 2026-11-21.
        Abilities::register(
            new RecordDiscount(),
            'Kedvezmény rögzítése',
            'Egy jelentkező fizetendő összegének kézi csökkentése, megnevezéssel.'
        );
    }
}
