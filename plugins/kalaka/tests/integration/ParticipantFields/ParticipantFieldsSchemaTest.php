<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\ParticipantFields;

use Kalaka\ParticipantFields\Module;
use Kalaka\ParticipantFields\ParticipantFieldQuery;
use Kalaka\Tests\Integration\Foundation\IndexCheck;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** The participant-field table: its columns, collations and key, and its read stays inside its event. */
final class ParticipantFieldsSchemaTest extends PerEventCostTestCase
{
    private const COLUMNS = [
        'event_id' => 'bigint(20) unsigned',
        'field_key' => 'varchar(64)',
        'version' => 'int(10) unsigned',
        'type' => 'varchar(191)',
        'title' => 'text',
        'options' => 'longtext null',
        'source' => 'varchar(16)',
        'retired_at' => 'datetime(6) null',
        'created_at' => 'datetime(6)',
        'updated_at' => 'datetime(6)',
        'position' => 'int(10) unsigned',
        'form_writable' => 'tinyint(1)',
    ];

    private const EXACT = ['field_key' => 'utf8mb4_nopad_bin', 'type' => 'utf8mb4_nopad_bin'];

    public function testTheTableHasItsColumnsAndTheDeclaredCollations(): void
    {
        global $wpdb;

        self::assertSame(1, (int) get_option('kalaka_schema_participant_fields'));
        $columns = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME'
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s'
            . ' ORDER BY ORDINAL_POSITION',
            $wpdb->prefix . 'kalaka_participant_fields'
        ), ARRAY_A);
        self::assertIsArray($columns);

        $declared = [];
        foreach ($columns as $column) {
            $nullable = $column['IS_NULLABLE'] === 'YES' ? ' null' : '';
            $declared[$column['COLUMN_NAME']] = $column['COLUMN_TYPE'] . $nullable;
            if ($column['CHARACTER_SET_NAME'] !== null) {
                self::assertSame('utf8mb4', $column['CHARACTER_SET_NAME']);
                self::assertSame(
                    self::EXACT[$column['COLUMN_NAME']] ?? 'utf8mb4_hungarian_ci',
                    $column['COLLATION_NAME'],
                    $column['COLUMN_NAME']
                );
            }
        }
        self::assertSame(self::COLUMNS, $declared);
    }

    public function testTheKeyIsAsDeclaredAndDbDeltaFindsNothingToChange(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $statements = array_map(
            static fn (string $statement): string => str_replace('{prefix}', $wpdb->prefix, $statement),
            (new Module())->schema()->tables
        );
        self::assertCount(1, $statements);
        self::assertSame([], IndexCheck::mismatches($wpdb->prefix . 'kalaka_participant_fields', $statements[0]));
        self::assertSame([], dbDelta($statements, false));
    }

    public function testTheEventsFieldsAreReadInsideTheEvent(): void
    {
        $this->assertPerEventCost(
            static function (int $event): void {
                global $wpdb;
                for ($n = 0; $n < 20; $n++) {
                    $wpdb->insert($wpdb->prefix . 'kalaka_participant_fields', [
                        'event_id' => $event,
                        'field_key' => 'field_' . $n,
                        'version' => 1,
                        'type' => 'kalaka/text-field',
                        'title' => 'Mező',
                        'source' => 'form',
                        'created_at' => '2026-11-13 08:00:00.000000',
                        'updated_at' => '2026-11-13 08:00:00.000000',
                        'position' => $n + 1,
                        'form_writable' => 0,
                    ]);
                }
            },
            static fn (int $event): array => (new ParticipantFieldQuery())->forEvent($event)
        );
    }
}
