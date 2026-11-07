<?php

declare(strict_types=1);

namespace Kalaka\Foundation;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Cli\PeriodicRunCommand;
use Kalaka\Foundation\Cli\SeedCommand;
use Kalaka\Foundation\Clock\WordPressClock;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\PeriodicRunHealth;
use Kalaka\Foundation\Periodic\PeriodicSchedule;
use Kalaka\Foundation\Periodic\TimeRules;
use Kalaka\Foundation\Seed\Seed;

final class Module extends AbstractModule
{
    /** @var list<Api\Module\Module> */
    private array $modules = [];

    private ?Contributions $contributions = null;

    /**
     * foundation.md, "Audit". JSON columns are `longtext`: MariaDB reports `json` as `longtext`,
     * so dbDelta's dry run would see a pending change on every boot.
     */
    private const AUDIT_ENTRIES = <<<'SQL'
CREATE TABLE {prefix}kalaka_audit_entries (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned DEFAULT NULL,
  correlation_id varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  occurred_at datetime(6) NOT NULL,
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

    /**
     * foundation.md, "Facts, decisions and computed values". Times are UTC, with microseconds so
     * that the latest record of a kind is also the latest in time.
     */
    private const DECISIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_decisions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  kind varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  state varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  effective_at datetime(6) NOT NULL,
  recorded_at datetime(6) NOT NULL,
  cause_type varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  cause_event_id bigint(20) unsigned DEFAULT NULL,
  cause_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  actor_type varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  actor_event_id bigint(20) unsigned DEFAULT NULL,
  actor_code varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY event_registration (event_id,code,kind,id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** foundation.md, "Failures": the "evaluation failed" flag, one row per registration. */
    private const EVALUATION_FAILURES = <<<'SQL'
CREATE TABLE {prefix}kalaka_evaluation_failures (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  rule varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  error longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  failed_at datetime(6) NOT NULL,
  PRIMARY KEY  (event_id,code)
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
        return new Schema(3, [self::AUDIT_ENTRIES, self::AUDIT_SUBJECTS, self::DECISIONS, self::EVALUATION_FAILURES]);
    }

    /**
     * Boot hands over what the seed needs once every module has contributed: the modules that
     * passed the version check, in boot order, and the contributions.
     *
     * @param list<Api\Module\Module> $modules
     */
    public function collect(array $modules, Contributions $contributions): void
    {
        $this->modules = $modules;
        $this->contributions = $contributions;

        // Registered here, not in boot(): the seed's drop is the recovery when this module's own
        // upgrade failed, and then boot() never runs. The runner is resolved only when needed.
        if (defined('WP_CLI') && WP_CLI) {
            $clock = new WordPressClock();
            SeedCommand::register(fn (): Seed => new Seed(
                $this->modules,
                $this->contributions ?? new Contributions(),
                static fn (): Runner => Runner::current(),
                $clock
            ));
        }
    }

    public function boot(): void
    {
        $clock = new WordPressClock();
        $runner = new Runner($clock, evaluation: new Evaluation(new DecisionRules()));
        Runner::publish($runner);

        $periodicRun = new PeriodicRun(
            $clock,
            new PeriodicEvaluation($runner),
            new TimeRules(),
            new Events(),
            new EvaluationFailureQuery()
        );
        (new PeriodicSchedule($clock))->register(static fn (): mixed => $periodicRun->run());
        (new PeriodicRunHealth($clock))->register();

        if (defined('WP_CLI') && WP_CLI) {
            PeriodicRunCommand::register($periodicRun);
        }
    }
}
