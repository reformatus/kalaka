<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\EventState;
use Kalaka\Tests\Integration\Foundation\Seed\SeedTestCase;

/** AC2: the seed day is 2026-11-10. */
final class EventSeederTest extends SeedTestCase
{
    public function testTheFiveEventsAreWrittenThroughTheEventUseCases(): void
    {
        $this->seeded();

        $events = $this->seededEvents();
        self::assertSame(
            ['Nyári tábor', 'Őszi hétvége', 'Téli tábor', 'Tavaszi találkozó', 'Tavalyi nyári tábor'],
            array_map(static fn (Event $event): string => $event->name, $events)
        );
        self::assertSame(
            [
                EventState::Published,
                EventState::Published,
                EventState::Draft,
                EventState::Unpublished,
                EventState::Archived,
            ],
            array_map(static fn (Event $event): EventState => $event->state(), $events)
        );
        self::assertSame(
            [40, 25, null, 30, 50],
            array_map(static fn (Event $event): ?int => $event->capacity, $events)
        );
        self::assertSame(
            [
                ['2026-10-27', '2026-12-10', '2027-01-09', '2027-01-15'],
                ['2026-12-10', '2027-01-09', '2027-01-24', '2027-01-26'],
                [null, null, null, null],
                ['2026-08-12', '2026-09-11', '2026-09-26', '2026-09-27'],
                ['2025-09-16', '2025-10-26', '2025-11-15', '2025-11-21'],
            ],
            array_map(fn (Event $event): array => $this->days($event), $events)
        );
        self::assertSame('08:00', $events[0]->registrationOpens?->setTimezone(wp_timezone())->format('H:i'));
        self::assertSame('20:00', $events[0]->registrationCloses?->setTimezone(wp_timezone())->format('H:i'));
        self::assertSame([['Előleg határideje', '2026-12-01']], $this->points($events[0]));
        foreach (array_slice($events, 1) as $event) {
            self::assertSame([], $event->customPoints);
        }
        foreach ($events as $event) {
            self::assertFalse($event->isFull);
        }
    }

    public function testOnlyTheFirstEventsRegistrationWindowIsOpenAtTheClocksTime(): void
    {
        $this->seeded();

        $events = $this->seededEvents();
        $now = $this->seedClock->now();
        self::assertTrue($events[0]->registrationWindowContains($now));
        self::assertFalse($events[1]->registrationWindowContains($now));
    }

    public function testEveryWriteIsAnAuditedUseCaseOfTheCliActor(): void
    {
        $this->seeded();

        $entries = $this->entries();
        self::assertCount(16, $entries);
        $names = [
            'kalaka/create-event',
            'kalaka/update-event',
            'kalaka/publish-event',
            'kalaka/unpublish-event',
            'kalaka/archive-event',
        ];
        foreach ($entries as $entry) {
            self::assertSame('cli', $entry['actor_type']);
            self::assertNull($entry['actor_user_id']);
            self::assertContains($entry['action'], $names);
            self::assertSame('succeeded', $entry['outcome']);
        }
        self::assertSame(
            ['create' => 5, 'update' => 4, 'publish' => 4, 'unpublish' => 2, 'archive' => 1],
            [
                'create' => count($this->entriesOf('kalaka/create-event')),
                'update' => count($this->entriesOf('kalaka/update-event')),
                'publish' => count($this->entriesOf('kalaka/publish-event')),
                'unpublish' => count($this->entriesOf('kalaka/unpublish-event')),
                'archive' => count($this->entriesOf('kalaka/archive-event')),
            ]
        );
    }

    /** @return list<Event> */
    private function seededEvents(): array
    {
        global $wpdb;
        $events = [];
        foreach ($wpdb->get_col("SELECT id FROM {$wpdb->prefix}kalaka_events ORDER BY id") as $id) {
            $event = $this->events->find((int) $id);
            self::assertNotNull($event);
            $events[] = $event;
        }

        return $events;
    }

    /** @return list<?string> */
    private function days(Event $event): array
    {
        return array_map(
            static fn (?\DateTimeImmutable $day): ?string => $day?->setTimezone(wp_timezone())->format('Y-m-d'),
            [$event->registrationOpens, $event->registrationCloses, $event->start, $event->end]
        );
    }

    /** @return list<array{string, string}> */
    private function points(Event $event): array
    {
        return array_map(
            static fn ($point): array => [$point->name, $point->at->setTimezone(wp_timezone())->format('Y-m-d')],
            $event->customPoints
        );
    }
}
