<?php

declare(strict_types=1);

namespace Kalaka\Extras;

use Kalaka\Extras\Donation\AmountFieldType;
use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Extras\Lunch\LunchComponent;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Module\Schema;

final class Module extends AbstractModule
{
    /**
     * money.md, "Core money scope": an event's lunch items, one row per item, keyed by (event ID, item key);
     * `position` is the order. `availability` is the item's condition as JSON (null: for everyone). The
     * amounts are read as they are now: there is no tariff snapshot.
     */
    private const LUNCH_ITEMS = <<<'SQL'
CREATE TABLE {prefix}kalaka_extras_lunch_items (
  event_id bigint(20) unsigned NOT NULL,
  item_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  position int(10) unsigned NOT NULL,
  label varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  amount decimal(26,8) NOT NULL,
  currency char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  availability longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci DEFAULT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id,item_key)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** The event's donation setting: the participant field (an amount field) whose value is charged. */
    private const DONATIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_extras_donations (
  event_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  version int(10) unsigned NOT NULL,
  PRIMARY KEY  (event_id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(private readonly AmountFieldType $amountField = new AmountFieldType())
    {
    }

    public function id(): string
    {
        return 'extras';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::LUNCH_ITEMS, self::DONATIONS]);
    }

    public function contribute(Contributions $to): void
    {
        $to->fieldType($this->amountField);
        $to->feeComponent(new LunchComponent());
        $to->feeComponent(new DonationComponent());
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register']);
    }

    /**
     * Registers the amount field's block from `build/`, where the JavaScript build writes it (engineering.md,
     * "Builds"). Without a build it is not registered, and a published form holding it closes the site.
     *
     * @internal the `init` action
     */
    public function register(): void
    {
        $block = dirname(__DIR__, 2) . '/build/Extras/Blocks/amount-field';
        if (is_file($block . '/block.json')) {
            register_block_type($block);
        }
    }
}
