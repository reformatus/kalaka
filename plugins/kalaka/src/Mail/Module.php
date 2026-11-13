<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Mail\Api\MailQueue;
use Kalaka\Mail\Pause\PauseCause;
use Kalaka\Mail\Pause\PauseWarning;
use Kalaka\Mail\Pause\RequestState;

final class Module extends AbstractModule
{
    /**
     * mail.md, "Words", "Five kinds of mail" and "Failures". Times are UTC `datetime(6)`; `error` is the
     * text of the latest error, a `longtext` for the same reason as the audit's JSON columns. `once`
     * is the once-only key; `event_status` serves the sender's candidates and the per-event counts;
     * `claimed_at` serves the site-wide hourly budget; `status_id` the site-wide pending count and the
     * clearing of the queue.
     */
    private const DELIVERIES = <<<'SQL'
CREATE TABLE {prefix}kalaka_deliveries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  mail varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  cause_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  cause_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  kind varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  status varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  queued_at datetime(6) NOT NULL,
  claimed_at datetime(6) DEFAULT NULL,
  sent_at datetime(6) DEFAULT NULL,
  mail_version varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  error longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY once (event_id,mail,code,cause_type,cause_key),
  KEY event_status (event_id,status),
  KEY claimed_at (claimed_at),
  KEY status_id (status,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * mail.md, "Pausing": the sender's one state row (`id` 1), with the pause, the errors in a row and the
     * hourly budget. `error` is the error of the delivery that paused sending.
     */
    private const STATES = <<<'SQL'
CREATE TABLE {prefix}kalaka_mail_states (
  id tinyint(3) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  paused_at datetime(6) DEFAULT NULL,
  cause varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  paused_by bigint(20) unsigned DEFAULT NULL,
  delivery_id bigint(20) unsigned DEFAULT NULL,
  delivery_event_id bigint(20) unsigned DEFAULT NULL,
  error longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  errors_in_row int(10) unsigned NOT NULL DEFAULT 0,
  hourly_budget int(10) unsigned NOT NULL DEFAULT 240,
  PRIMARY KEY  (id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    private ?Sender $sender = null;

    public function id(): string
    {
        return 'mail';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(2, [self::DELIVERIES, self::STATES]);
    }

    public function contribute(Contributions $to): void
    {
        $state = new RequestState();
        $to->siteWarning(new PauseWarning(PauseCause::Errors, $state));
        $to->siteWarning(new PauseWarning(PauseCause::Manual, $state));
        $to->periodicStep($this->sender());
    }

    public function boot(): void
    {
        $sender = $this->sender();
        add_action(MailQueue::SEND_HOOK, static function () use ($sender): void {
            Boundary::background('mail sender', static fn (): mixed => $sender->send());
        });
    }

    private function sender(): Sender
    {
        // No composer is bound until the mail bodies are built, so the sender idles.
        return $this->sender ??= new Sender(static fn (): Runner => Runner::current(), null);
    }
}
