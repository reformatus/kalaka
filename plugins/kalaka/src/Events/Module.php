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
     * events-and-forms.md, "Events". Points are zone-free `date` columns; the custom points are
     * JSON in `points`, a `longtext` for the same reason as the audit's JSON columns.
     */
    private const EVENTS = <<<'SQL'
CREATE TABLE {prefix}kalaka_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version int(10) unsigned NOT NULL,
  name varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  state varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  capacity int(10) unsigned DEFAULT NULL,
  is_full tinyint(1) NOT NULL DEFAULT 0,
  registration_opens_on date DEFAULT NULL,
  registration_closes_on date DEFAULT NULL,
  starts_on date DEFAULT NULL,
  ends_on date DEFAULT NULL,
  points longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  next_point_id int(10) unsigned NOT NULL DEFAULT 1,
  archived_at datetime DEFAULT NULL,
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
        return new Schema(1, [self::EVENTS]);
    }

    public function contribute(Contributions $to): void
    {
        $to->seeder(new EventSeeder());
    }

    public function boot(): void
    {
    }
}
