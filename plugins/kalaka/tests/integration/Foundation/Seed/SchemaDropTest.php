<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Seed;

use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\KalakaException;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Foundation\Seed\Seed;
use Kalaka\Foundation\Seed\SeedFailure;
use Kalaka\Foundation\Seed\SchemaDrop;
use Kalaka\Tests\Integration\Foundation\RealTableTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

require_once __DIR__ . '/../RealTableTestCase.php';
require_once __DIR__ . '/SeedTestCase.php';

/**
 * AC4 (the drop half), AC5 and the drop half of AC7. The drop is DDL, which commits, so these tests
 * use real tables; the schema is recreated by the drop itself, and the undeclared table is dropped
 * at the end.
 */
final class SchemaDropTest extends RealTableTestCase
{
    /** @var list<LoadFailure> */
    private array $loadFailures = [];

    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->loadFailures = LoadState::current()->failures;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_undeclared");
        $wpdb->query("CREATE TABLE {$wpdb->prefix}kalaka_test_undeclared (id INT PRIMARY KEY) ENGINE=InnoDB");
        $wpdb->query("INSERT INTO {$wpdb->prefix}kalaka_test_undeclared (id) VALUES (1)");
    }

    public function tear_down(): void
    {
        global $wpdb;
        LoadState::record($this->loadFailures);
        ObjectCache::clear();
        // The drop's DDL ended the harness transaction; commit the schema versions it set afterwards.
        $wpdb->query('COMMIT');

        parent::tear_down();

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}kalaka_test_undeclared");
    }

    public function testItDropsAndRecreatesEveryDeclaredTableAndNothingElse(): void
    {
        global $wpdb;
        (new Runner(new TestClock()))->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
        $users = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        self::assertGreaterThan(0, $this->rows('kalaka_events'));
        self::assertGreaterThan(0, $this->rows('kalaka_audit_entries'));
        self::assertGreaterThan(0, $this->rows('kalaka_audit_subjects'));
        $wpdb->query("INSERT INTO {$wpdb->prefix}kalaka_evaluation_failures (event_id, code, version, rule, error, failed_at) VALUES (1, 'A', 1, 'r', 'e', '2026-11-10 12:00:00')");
        self::assertSame(1, $this->rows('kalaka_evaluation_failures'));

        (new SchemaDrop(SeedTestCase::modulesForTests()))->run();

        foreach (['kalaka_events', 'kalaka_audit_entries', 'kalaka_audit_subjects', 'kalaka_decisions', 'kalaka_evaluation_failures'] as $table) {
            self::assertSame($wpdb->prefix . $table, $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$table}'"));
            self::assertSame(0, $this->rows($table));
        }
        foreach (SeedTestCase::modulesForTests() as $module) {
            self::assertSame($module->schema()->version, (int) get_option('kalaka_schema_' . $module->id(), 0));
        }
        self::assertSame(1, $this->rows('kalaka_test_undeclared'));
        self::assertSame($users, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"));
    }

    public function testTheDropRefusesOnProductionLikeSitesBeforeTouchingAnything(): void
    {
        (new Runner(new TestClock()))->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
        $events = $this->rows('kalaka_events');
        $entries = $this->rows('kalaka_audit_entries');
        foreach (['staging', 'production'] as $type) {
            $seed = $this->seed();
            try {
                $seed->run(true, $type, static function (string $line): void {
                });
                self::fail('The drop should have been refused.');
            } catch (SeedFailure $e) {
                self::assertSame(
                    'Refused: the environment type is "' . $type . '"; the seed runs only on local or development sites.',
                    $e->getMessage()
                );
            }
        }

        self::assertSame($events, $this->rows('kalaka_events'));
        self::assertSame($entries, $this->rows('kalaka_audit_entries'));
        self::assertSame(1, $this->rows('kalaka_test_undeclared'));
        self::assertSame(2, (int) get_option('kalaka_schema_events', 0));
    }

    public function testADropOnAClosedSiteRecreatesTheSchemaAndSeedsNothing(): void
    {
        (new Runner(new TestClock()))->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
        LoadState::record([new LoadFailure('x', 'y')]);

        $lines = [];
        $message = $this->seed()->run(true, 'local', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('Schema recreated; run "wp kalaka seed" again.', $message);
        self::assertSame([], $lines);
        self::assertSame(0, $this->rows('kalaka_events'));
        self::assertSame(0, $this->rows('kalaka_audit_entries'));
        self::assertSame(2, (int) get_option('kalaka_schema_events', 0));
        foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $login) {
            self::assertFalse(get_user_by('login', $login));
        }
    }

    public function testAFailedDropStatementIsASeedFailure(): void
    {
        global $wpdb;
        $child = $wpdb->prefix . 'kalaka_test_child';
        $wpdb->query("DROP TABLE IF EXISTS {$child}");
        $wpdb->query(
            "CREATE TABLE {$child} (id INT PRIMARY KEY, event_id BIGINT(20) UNSIGNED NOT NULL,"
            . " FOREIGN KEY (event_id) REFERENCES {$wpdb->prefix}kalaka_events (id)) ENGINE=InnoDB"
        );
        $wpdb->query("INSERT INTO {$wpdb->prefix}kalaka_events (version, name, state, points) VALUES (1, 'Tábor', 'draft', '[]')");
        $wpdb->query("INSERT INTO {$child} (id, event_id) VALUES (1, {$wpdb->insert_id})");

        $wpdb->suppress_errors(true);
        try {
            $this->seed()->run(true, 'local', static function (string $line): void {
            });
            self::fail('The failed drop should have stopped the seed.');
        } catch (SeedFailure $e) {
            self::assertStringStartsWith('Dropping ' . $wpdb->prefix . 'kalaka_events: Database error 1451', $e->getMessage());
            self::assertInstanceOf(KalakaException::class, $e->getPrevious());
        } finally {
            $wpdb->suppress_errors(false);
            $wpdb->query("DROP TABLE IF EXISTS {$child}");
            // The statements before the failed one already dropped their tables; recreate the schema.
            (new SchemaDrop(SeedTestCase::modulesForTests()))->run();
        }
    }

    private function seed(): Seed
    {
        return new Seed(
            SeedTestCase::modulesForTests(),
            new Contributions(),
            static fn (): Runner => new Runner(new TestClock()),
            new TestClock()
        );
    }

    private function rows(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
    }
}
