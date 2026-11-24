<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

use Kalaka\Admin\Api\Abilities;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Registrations\Api\Requests;
use Kalaka\Registrations\Cli\RegistrationSeeder;

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
     * money.md, "Sponsor and church support" and foundation.md, "Facts": the answers to requests, a fact, one
     * row per answering act (never an upsert: each act has its own id, the key the "answered" mail takes, and its
     * own time). `request` is the request's id (`aid`, `offer:<key>`); the latest row per request is the current
     * answer. `amount` and `currency` are set for aid, `note` is free text. [UNAPPROVED N1]
     */
    private const ANSWERS = <<<'SQL'
CREATE TABLE {prefix}kalaka_answers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  request varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  approved tinyint(1) unsigned NOT NULL,
  amount decimal(26,8) DEFAULT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  note varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  answered_at datetime(6) NOT NULL,
  answered_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY event_registration (event_id,code,request,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * foundation.md, "Facts, decisions and computed values", the seat rule: one row per seat held, by event,
     * registration and pool (`event` is never a pool here: the event's seats are its `registered` statuses).
     * `pool` is `utf8mb4_nopad_bin`, compared exactly; `act` is the registration's own act that took the seat.
     * `KEY pool` serves the count of a pool. A freed seat has no row. [UNAPPROVED N1]
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
        // 6: #229's seats take 5, the answers of slice B take 6.
        return new Schema(6, [self::REGISTRATIONS, self::FIELD_VALUES, self::SUBMISSIONS, self::ANSWERS, self::SEATS]);
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
            'kalaka_regenerate_link',
            Requests::ANSWER,
        ];
    }

    public function contribute(Contributions $to): void
    {
        $to->siteWarning(new Account\SettingsWarning());
        $to->seeder(new RegistrationSeeder());
    }

    public function boot(): void
    {
        // S15: WordPress's own registration page as Kaláka's sign-up.
        (new Account\SignUpPage())->register();

        // The answer act's label and description are placeholders until the owner's words. [UNAPPROVED T1, T2]
        Abilities::register(
            new Answer\AnswerRequest(),
            'Kérelem megválaszolása',
            'Egy jelentkező kérelmének megválaszolása: támogatási kérelem vagy jóváhagyásra váró támogatás.'
        );
    }
}
