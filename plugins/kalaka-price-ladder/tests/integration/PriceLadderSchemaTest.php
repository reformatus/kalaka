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
        self::assertSame('1', (string) get_option('kalaka_schema_' . (new Module())->id()));
        self::assertContains(
            PriceLadderComponent::ID,
            array_map(static fn ($component): string => $component->id(), Contributions::current()->feeComponents())
        );
    }
}
