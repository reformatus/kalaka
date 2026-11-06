<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use WP_UnitTestCase;

final class DatabaseErrorTest extends WP_UnitTestCase
{
    private string $table;

    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->table = $wpdb->prefix . 'kalaka_test_unique';
        $wpdb->query("CREATE TABLE {$this->table} (code VARCHAR(10) NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        $wpdb->suppress_errors(true);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->suppress_errors(false);
        $wpdb->query("DROP TABLE IF EXISTS {$this->table}");

        parent::tear_down();
    }

    public function testNothingHappensAfterASuccessfulStatement(): void
    {
        global $wpdb;
        $wpdb->insert($this->table, ['code' => 'A']);

        DatabaseError::check($wpdb);
        DatabaseError::check($wpdb, true);

        $this->addToAssertionCount(1);
    }

    public function testADuplicateKeyIsASystemFailure(): void
    {
        global $wpdb;
        $wpdb->insert($this->table, ['code' => 'A']);
        $wpdb->insert($this->table, ['code' => 'A']);

        try {
            DatabaseError::check($wpdb);
            self::fail('check() should have thrown.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertSame('Váratlan hiba történt. Próbáld újra később.', $caught->getMessage());
            self::assertStringContainsString('1062', (string) $caught->getPrevious()?->getMessage());
        }
    }

    public function testADuplicateKeyOnAReferenceCodeInsertIsRetryable(): void
    {
        global $wpdb;
        $wpdb->insert($this->table, ['code' => 'A']);
        $wpdb->insert($this->table, ['code' => 'A']);

        $this->expectException(RetryableFailure::class);
        DatabaseError::check($wpdb, true);
    }

    public function testASuccessfulStatementAfterAFailedOneClearsTheError(): void
    {
        global $wpdb;
        $wpdb->insert($this->table, ['code' => 'A']);
        $wpdb->insert($this->table, ['code' => 'A']);
        $wpdb->insert($this->table, ['code' => 'B']);

        DatabaseError::check($wpdb);

        $this->addToAssertionCount(1);
    }
}
