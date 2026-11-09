<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Admin\Api\AdminAccess;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/** The base of the audit tests: reads the entries a test wrote, with a runner in savepoint mode. */
abstract class AuditTestCase extends RunnerTestCase
{
    private int $firstEntryId = 0;

    /** @var array<string, mixed> */
    private array $server = [];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        global $wpdb;
        $this->firstEntryId = (int) $wpdb->get_var(
            "SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}kalaka_audit_entries"
        );
        $this->server = $_SERVER;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        $_SERVER = $this->server;

        parent::tear_down();
    }

    /** Writes a registration row for each key directly, behind the repository, so that a run can hold it. */
    protected function seedRegistrations(RegistrationKey ...$keys): void
    {
        global $wpdb;
        foreach ($keys as $key) {
            $wpdb->insert($wpdb->prefix . 'kalaka_registrations', [
                'event_id' => $key->eventId,
                'code' => $key->code,
                'version' => 1,
                'status' => 'registered',
                'secret' => 'secret',
                'created_at' => '2026-11-29 08:00:00.000000',
            ]);
        }
    }

    /**
     * Locks the registrations in key order, as a use case does before it writes their records: the
     * evaluation would otherwise lock them after those writes, which the lock order refuses. A key
     * without a row holds nothing: seed the row first.
     */
    protected function lockRegistrations(Context $context, RegistrationKey ...$keys): void
    {
        usort(
            $keys,
            static fn (RegistrationKey $a, RegistrationKey $b): int => $a->eventId <=> $b->eventId
                ?: strcmp($a->code, $b->code)
        );
        foreach ($keys as $key) {
            (new Registrations())->lock($key, $context);
        }
    }

    /** @return list<array<string, ?string>> the entries this test wrote, oldest first */
    protected function entries(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}kalaka_audit_entries WHERE id > %d ORDER BY id",
                $this->firstEntryId
            ),
            ARRAY_A
        );

        return array_values(is_array($rows) ? $rows : []);
    }

    /** @return array<string, ?string> */
    protected function onlyEntry(): array
    {
        $entries = $this->entries();
        self::assertCount(1, $entries);

        return $entries[0];
    }

    /** @return list<string> `type/event/key` of each subject row of an entry, in the order written */
    protected function subjectsOf(string|int $entryId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}kalaka_audit_subjects WHERE entry_id = %d ORDER BY id",
                (int) $entryId
            ),
            ARRAY_A
        );

        return array_map(
            static fn (array $row): string => $row['record_type'] . '/' . ($row['event_id'] ?? '')
                . '/' . $row['record_key'],
            is_array($rows) ? $rows : []
        );
    }

    /** @return array<string, mixed> the JSON column of an entry, decoded */
    protected function decode(?string $json): array
    {
        self::assertIsString($json);
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    /** A WordPress user who passes the admin side's full-access check. */
    protected function fullAccessUser(): int
    {
        $id = self::factory()->user->create();
        $user = get_userdata($id);
        self::assertInstanceOf(\WP_User::class, $user);
        $user->add_cap(AdminAccess::FULL_ACCESS);

        return $id;
    }

    /** A clock fixed at the given local time in the site's zone. */
    protected function clockAt(string $local, string $zone = 'Europe/Budapest'): TestClock
    {
        return new TestClock(new \DateTimeImmutable($local, new \DateTimeZone($zone)));
    }
}
