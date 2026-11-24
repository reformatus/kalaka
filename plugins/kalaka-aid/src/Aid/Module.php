<?php

declare(strict_types=1);

namespace Kalaka\Aid;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Core money scope": an event's adjudicated aid is on when it has a row here, keyed by the event.
     * `label` is the organizers' own name for the aid, shown to staff on the request. [UNAPPROVED N114]
     */
    private const SETTINGS = <<<'SQL'
CREATE TABLE {prefix}kalaka_aid_settings (
  event_id bigint(20) unsigned NOT NULL,
  label varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'aid';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::SETTINGS]);
    }

    public function contribute(Contributions $to): void
    {
        $to->feeComponent(new AidComponent());
        $to->requestSource(new AidRequests());
    }

    public function boot(): void
    {
    }
}
