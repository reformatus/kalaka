<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\PriceLadder;

use Kalaka\PriceLadder\Module;
use Kalaka\PriceLadder\PriceLadderComponent;
use Kalaka\Foundation\Api\Module\Contributions;

/** The module's table and its contribution. */
final class PriceLadderSchemaTest extends PriceLadderTestCase
{
    public function testTheTiersTableExistsAndTheComponentIsContributed(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kalaka_price_ladder_tiers';
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        self::assertSame('2', (string) get_option('kalaka_schema_' . (new Module())->id()));
        self::assertContains(
            PriceLadderComponent::ID,
            array_map(static fn ($component): string => $component->id(), Contributions::current()->feeComponents())
        );
        self::assertCount(1, Contributions::current()->slotRequirements());
    }

    public function testTheFieldDeclarerIsTheModulesId(): void
    {
        self::assertSame((new Module())->id(), PriceLadderComponent::DECLARER);
    }

    public function testATierHasNoConditionNoDeadlineAndNoShareColumn(): void
    {
        global $wpdb;

        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->prefix . 'kalaka_price_ladder_tiers', 0);
        self::assertSame(
            ['event_id', 'tier_key', 'position', 'label', 'amount', 'currency', 'version', 'seat_limit'],
            $columns
        );
    }
}
