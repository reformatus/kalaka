<?php

declare(strict_types=1);

namespace Kalaka\Share;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Congregation share": an event's share is on when it has a row here, keyed by the event. There is
     * nothing else to store: the mode and the amount are the registrant's answers. [UNAPPROVED N141]
     */
    private const SETTINGS = <<<'SQL'
CREATE TABLE {prefix}kalaka_share_settings (
  event_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'share';
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
        $to->feeComponent(new ShareComponent());
        $to->payerKindSource(new SharePayerKind());
    }

    public function boot(): void
    {
    }
}
