<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Extras\Donation\DonationComponent;
use Kalaka\Extras\ExtrasComponent;
use Kalaka\Extras\Module;
use Kalaka\Foundation\Api\Module\Contributions;

/** The module's tables and its contributions, now in core. */
final class ExtrasSchemaTest extends ExtrasTestCase
{
    public function testTheTablesExistAndTheComponentsAndTheNumberFieldAreContributed(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'kalaka_extras_charges';
        self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'field_key'));
        self::assertSame([], $columns, 'A charge names no field.');
        $donations = $wpdb->prefix . 'kalaka_extras_donations';
        self::assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $donations)));
        self::assertSame('2', (string) get_option('kalaka_schema_' . (new Module())->id()));
        $ids = array_map(static fn ($component): string => $component->id(), Contributions::current()->feeComponents());
        self::assertContains(ExtrasComponent::ID, $ids);
        self::assertContains(DonationComponent::ID, $ids);
        self::assertNotContains('lunch', $ids);
        self::assertArrayHasKey('kalaka/number-field', Contributions::current()->fieldTypes());
    }
}
