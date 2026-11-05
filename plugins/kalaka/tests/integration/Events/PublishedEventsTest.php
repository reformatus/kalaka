<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventState;
use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\UnpublishEvent;

require_once __DIR__ . '/EventsTestCase.php';

/** D9: `Events::published()`. */
final class PublishedEventsTest extends EventsTestCase
{
    public function testItReturnsTheEventsWhoseStoredStateIsPublishedAscendingById(): void
    {
        $one = $this->published();
        $this->unpublished();
        $this->archived();
        $this->create();
        $five = $this->published();

        $events = $this->events->published();

        self::assertSame([$one, $five], array_map(static fn ($event): int => $event->id, $events));
        self::assertEquals($this->events->find($one), $events[0]);
        self::assertEquals($this->events->find($five), $events[1]);
        self::assertSame(EventState::Published, $events[0]->state());
        self::assertSame(41, $events[0]->capacity);
    }

    public function testNoPublishedEventIsAnEmptyList(): void
    {
        $this->create();

        self::assertSame([], $this->events->published());
    }

    public function testItIsOneStatementOnTheEventTable(): void
    {
        $this->published();
        $this->published();
        $this->unpublished();

        $statements = $this->statementsDuring(fn () => $this->events->published());

        self::assertCount(1, $statements);
        self::assertStringContainsString('kalaka_events', $statements[0]);
        self::assertStringContainsString("state = 'published'", $statements[0]);
    }

    public function testTheStatementUsesTheStateIndex(): void
    {
        global $wpdb;
        $this->published();

        $plan = $wpdb->get_results(
            "EXPLAIN SELECT * FROM {$wpdb->prefix}kalaka_events WHERE state = 'published' ORDER BY id",
            ARRAY_A
        );

        self::assertIsArray($plan);
        self::assertSame('state', $plan[0]['key'] ?? null);
    }
}
