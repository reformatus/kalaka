<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\UseCase\UseCase;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Tests\Integration\Foundation\AuditTestCase;

/** The base of the Admin tests: a full-access actor and helpers that write registration data behind the repositories. */
abstract class AdminTestCase extends AuditTestCase
{
    protected UserActor $admin;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        ObjectCache::clear();
        $this->admin = new UserActor($this->fullAccessUser());
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function tear_down(): void
    {
        ObjectCache::clear();

        parent::tear_down();
    }

    /** @param array<string, mixed> $input */
    protected function exec(UseCase $useCase, array $input, ?Actor $actor = null): mixed
    {
        return $this->runner->run($useCase, $input, $actor ?? $this->admin);
    }

    protected function createEvent(string $name = 'Tábor'): int
    {
        $id = $this->exec(new CreateEvent(), ['name' => $name]);
        self::assertIsInt($id);

        return $id;
    }

    protected function addRegistration(
        int $event,
        string $code,
        string $status = 'registered',
        string $createdAt = '2026-11-30 08:00:00',
    ): void {
        $this->insert('kalaka_registrations', [
            'event_id' => $event,
            'code' => $code,
            'version' => 1,
            'status' => $status,
            'secret' => 'secret-of-' . $code,
            'created_at' => $createdAt . '.000000',
            'registered_at' => $status === 'registered' ? $createdAt . '.000000' : null,
        ]);
    }

    protected function addValue(int $event, string $code, string $key, string $value): void
    {
        $this->insert('kalaka_field_values', [
            'event_id' => $event,
            'code' => $code,
            'field_key' => $key,
            'version' => 1,
            'value' => $value,
        ]);
    }

    /** Appends a record; a later call for the same registration and kind is the newer state. */
    protected function addDecision(int $event, string $code, string $kind, string $state): void
    {
        $this->insert('kalaka_decisions', [
            'event_id' => $event,
            'code' => $code,
            'kind' => $kind,
            'state' => $state,
            'effective_at' => '2026-11-30 08:00:00.000000',
            'recorded_at' => '2026-11-30 08:00:00.000000',
            'cause_type' => 'test_record',
            'cause_event_id' => $event,
            'cause_key' => '1',
            'actor_type' => 'system',
        ]);
    }

    protected function viewCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_views");
    }

    /** @return array<string, ?string> */
    protected function viewRow(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}kalaka_views WHERE id = %d", $id), ARRAY_A);
        self::assertIsArray($row);

        return $row;
    }

    /** @param array<string, int|string|null> $row */
    private function insert(string $table, array $row): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . $table, $row);
        DatabaseError::check($wpdb);
    }
}
