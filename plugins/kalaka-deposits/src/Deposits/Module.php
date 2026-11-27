<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Confirmation" and "Withdrawal, removal and substitution": an event's deposit, one row keyed by
     * the event; `amount` is decimal text in the event's currency.
     */
    private const SETTINGS = <<<'SQL'
CREATE TABLE {prefix}kalaka_deposit_settings (
  event_id bigint(20) unsigned NOT NULL,
  amount varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'deposits';
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
        $to->feeComponent(new DepositComponent());
    }

    public function boot(): void
    {
    }
}
