<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Events\Api\Events;
use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\CreateEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Api\UseCase\UseCase;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\AuditTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

require_once __DIR__ . '/../Foundation/AuditTestCase.php';

/** The base of the Events tests: site zone Europe/Budapest, a full-access admin and helpers to reach a state. */
abstract class EventsTestCase extends AuditTestCase
{
    protected UserActor $admin;

    protected Events $events;

    public function set_up(): void
    {
        parent::set_up();

        update_option('timezone_string', 'Europe/Budapest');
        ObjectCache::clear();
        $this->admin = new UserActor($this->fullAccessUser());
        $this->events = new Events();
    }

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

    protected function useClock(TestClock $clock): void
    {
        $this->runner = new Runner($clock, true);
    }

    protected function create(string $name = 'Tábor'): int
    {
        $id = $this->exec(new CreateEvent(), ['name' => $name]);
        self::assertIsInt($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> a whole-record update with a complete set of days and a capacity
     */
    protected function updateInput(int $id, int $version, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'version' => $version,
            'name' => 'Tábor',
            'capacity' => 41,
            'registration_opens_at' => '2026-11-30 08:00',
            'registration_closes_at' => '2026-12-15 20:00',
            'starts_at' => '2027-07-05 10:00',
            'ends_at' => '2027-07-12 14:00',
            'points' => [],
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function update(int $id, array $overrides = []): void
    {
        $this->exec(new UpdateEvent(), $this->updateInput($id, $this->versionOf($id), $overrides));
    }

    /** A draft with every core point and a capacity of 41. */
    protected function filled(string $name = 'Tábor'): int
    {
        $id = $this->create($name);
        $this->update($id);

        return $id;
    }

    protected function published(): int
    {
        $id = $this->filled();
        $this->transition(new PublishEvent(), $id);

        return $id;
    }

    protected function unpublished(): int
    {
        $id = $this->published();
        $this->transition(new UnpublishEvent(), $id);

        return $id;
    }

    protected function archived(): int
    {
        $id = $this->unpublished();
        $this->transition(new ArchiveEvent(), $id);

        return $id;
    }

    protected function transition(UseCase $useCase, int $id, ?Actor $actor = null): void
    {
        $this->exec($useCase, ['id' => $id, 'version' => $this->versionOf($id)], $actor);
    }

    protected function markFull(int $id): void
    {
        $this->runner->run(
            new AdminProbe(fn (array $input, Context $context): mixed => (new EventCapacity())->markFull($context, $id)),
            [],
            new CliActor(null)
        );
    }

    protected function versionOf(int $id): int
    {
        return (int) $this->row($id)['version'];
    }

    /** @return array<string, ?string> */
    protected function row(int $id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}kalaka_events WHERE id = %d", $id), ARRAY_A);
        self::assertIsArray($row);

        return $row;
    }

    protected function eventCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_events");
    }

    /** @return list<string> the statements the database receives while the work runs */
    protected function statementsDuring(\Closure $work): array
    {
        $seen = [];
        $capture = static function (string $query) use (&$seen): string {
            $seen[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $seen;
    }

    /** @return list<array<string, ?string>> the entries of one action */
    protected function entriesOf(string $action): array
    {
        return array_values(array_filter($this->entries(), static fn (array $entry): bool => $entry['action'] === $action));
    }

    /** @return list<array<string, ?string>> */
    protected function failedEntries(): array
    {
        return array_values(array_filter($this->entries(), static fn (array $entry): bool => $entry['outcome'] === 'failed'));
    }

    /**
     * @param array<string, ?string> $entry
     * @return list<string> the changed fields of the entry, in the order recorded
     */
    protected function changedFields(array $entry): array
    {
        return array_map(static fn (array $change): string => (string) $change['field'], $this->changesOf($entry));
    }

    /**
     * @param array<string, ?string> $entry
     * @return list<array<string, mixed>>
     */
    protected function changesOf(array $entry): array
    {
        /** @var list<array<string, mixed>> */
        return $this->decode($entry['changes']);
    }

    /** @return list<string> */
    protected function statementsOnEvents(\Closure $work): array
    {
        return array_values(array_filter(
            $this->statementsDuring($work),
            static fn (string $sql): bool => str_contains($sql, 'kalaka_events') && !str_starts_with($sql, 'SELECT')
        ));
    }

    protected function local(string $time, string $zone = 'Europe/Budapest'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone($zone));
    }
}
