<?php

declare(strict_types=1);

namespace Kalaka\Assignments;

use Kalaka\Assignments\Cli\AssignmentCommand;
use Kalaka\Assignments\Rest\AssignmentSetsRoute;
use Kalaka\Foundation\Api\Database\SchemaStep;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * See modules-and-extensions.md, "Assignment sets": one row per set of an event. A set has no
     * window of its own (owner ruling 2026-11-25); `request_field_key` is the set's one request field (S44-1),
     * `utf8mb4_nopad_bin` like every field key.
     */
    private const SETS = <<<'SQL'
CREATE TABLE {prefix}kalaka_assignment_sets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  name varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  time_slot varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  required tinyint(1) NOT NULL DEFAULT 0,
  mode varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  published tinyint(1) NOT NULL DEFAULT 0,
  request_field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * One row per option of a set, keyed by set and option key (`utf8mb4_nopad_bin`, compared exactly).
     * Options show by `position`, then key.
     */
    private const OPTIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_assignment_options (
  set_id bigint(20) unsigned NOT NULL,
  option_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  label varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  capacity int(10) unsigned DEFAULT NULL,
  leaders varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  place varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (set_id,option_key),
  KEY event_set (event_id,set_id,position)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /**
     * The assignment facts: at most one per registration and set, keyed event first. `KEY set_option`
     * serves the later tallies and rosters (count per option).
     */
    private const ASSIGNMENTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_assignments (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  set_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  option_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  source varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  state varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  PRIMARY KEY  (event_id,code,set_id),
  KEY set_option (event_id,set_id,option_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'assignments';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(2, [self::SETS, self::OPTIONS, self::ASSIGNMENTS], [
            2 => self::dropSetWindow(...),
        ]);
    }

    /**
     * Schema step 2: a set has no choice window (owner ruling 2026-11-25), so its two columns go. dbDelta never
     * drops a column; each is dropped only where it exists, so the step is safe to run again.
     */
    private static function dropSetWindow(): void
    {
        SchemaStep::dropColumnIfExists('kalaka_assignment_sets', 'window_opens_at');
        SchemaStep::dropColumnIfExists('kalaka_assignment_sets', 'window_closes_at');
    }

    public function contribute(Contributions $to): void
    {
        $to->seatClaimSource(new SetOptionClaimSource());
    }

    public function boot(): void
    {
        add_action('rest_api_init', [AssignmentSetsRoute::class, 'register']);
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('kalaka assignment', AssignmentCommand::class);
        }
    }
}
