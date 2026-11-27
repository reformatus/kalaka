<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * registrations-and-identity.md, "Registrations". Keyed by (event ID, reference code); times are
     * UTC `datetime(6)`; `secret` is `utf8mb4_nopad_bin`, compared exactly. `KEY code` serves the site-wide
     * `code IN (…)` of `ReferenceCodeQuery`. `owner_user_id` is the owner account (S15): the WordPress user
     * the registration belongs to, null while it has none; set once, by a logged-in person's submit, and
     * never changed after.
     */
    private const REGISTRATIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_registrations (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  status varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  secret varchar(22) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  created_at datetime(6) NOT NULL,
  registered_at datetime(6) DEFAULT NULL,
  owner_user_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (event_id,code),
  KEY code (code)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * registrations-and-identity.md, "Submissions and values": one row per answered field. `field_key` is
     * `utf8mb4_nopad_bin`, so `Email`, `email` and `email ` are three keys.
     */
    private const FIELD_VALUES = <<<'SQL'
CREATE TABLE {prefix}kalaka_field_values (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  value longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (event_id,code,field_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * registrations-and-identity.md, "Submissions and values": append-only; `answers` is a JSON object.
     * `submit_token` is the form page's token: a repeated submit with it and the same answers creates
     * nothing and is answered only as a repeat (`{repeated: true, after_submit}`); `utf8mb4_nopad_bin`,
     * compared exactly, and unique within the event. `answers_hash` is the SHA-256 fingerprint of the
     * stored answers that a repeat with the token must match.
     */
    private const SUBMISSIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_submissions (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  number int(10) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  form_version bigint(20) unsigned NOT NULL,
  answers longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  submitted_at datetime(6) NOT NULL,
  submit_token varchar(22) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  answers_hash varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  PRIMARY KEY  (event_id,code,number),
  UNIQUE KEY submit_token (event_id,submit_token)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * foundation.md, "Facts, decisions and computed values", the seat rule: one row per seat held, by event,
     * registration and pool (`event` is never a pool here: the event's seats are its `registered` statuses).
     * `pool` is `utf8mb4_nopad_bin`, compared exactly; `act` is the registration's own act that took the seat.
     * `KEY pool` serves the count of a pool. A freed seat has no row.
     */
    private const SEATS = <<<'SQL'
CREATE TABLE {prefix}kalaka_seats (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  pool varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  taken_at datetime(6) NOT NULL,
  act varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (event_id,code,pool),
  KEY pool (event_id,pool)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'registrations';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        // Version 4 is #194's (drafts). Whichever of the two merges second renumbers, if #194 lands later.
        return new Schema(5, [self::REGISTRATIONS, self::FIELD_VALUES, self::SUBMISSIONS, self::SEATS]);
    }

    /**
     * The per-event actions grants may carry (foundation.md, "Views and grants").
     */
    public function capabilities(): array
    {
        return [
            'kalaka_withdraw_registration',
            'kalaka_remove_registration',
            'kalaka_admit_registration',
        ];
    }

    public function contribute(Contributions $to): void
    {
        $to->siteWarning(new Account\SettingsWarning());
    }

    public function boot(): void
    {
        // S15: WordPress's own registration page as Kaláka's sign-up.
        (new Account\SignUpPage())->register();
    }
}
