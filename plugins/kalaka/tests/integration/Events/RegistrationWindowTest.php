<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;

/** AC6: the registration window is computed on every read. */
final class RegistrationWindowTest extends EventsTestCase
{
    /** @return iterable<string, array{string}> */
    public static function zones(): iterable
    {
        yield 'Europe/Budapest' => ['Europe/Budapest'];
        yield 'UTC' => ['UTC'];
        yield 'America/New_York' => ['America/New_York'];
    }

    /** @dataProvider zones */
    public function testAPublishedEventIsOpenFromTheOpeningInstantUpToButNotTheClosingInstant(string $zone): void
    {
        update_option('timezone_string', $zone);
        $id = $this->published();
        $event = $this->events->find($id);
        self::assertNotNull($event);

        self::assertFalse($event->registrationWindowContains($this->local('2026-11-30 07:59:59', $zone)));
        self::assertTrue($event->registrationWindowContains($this->local('2026-11-30 08:00:00', $zone)));
        self::assertTrue($event->registrationWindowContains($this->local('2026-12-15 19:59:59', $zone)));
        self::assertFalse($event->registrationWindowContains($this->local('2026-12-15 20:00:00', $zone)));
    }

    public function testTheMomentIsComparedAsAnInstantWhateverZoneItIsGivenIn(): void
    {
        $id = $this->published();
        $event = $this->events->find($id);
        self::assertNotNull($event);

        self::assertTrue($event->registrationWindowContains($this->local('2026-11-30 07:00:00', 'UTC')));
        self::assertFalse($event->registrationWindowContains($this->local('2026-11-30 06:59:59', 'UTC')));
    }

    public function testADraftOrAnUnpublishedEventInsideTheWindowIsNotOpen(): void
    {
        $inside = $this->local('2026-12-01 12:00:00');

        $draft = $this->events->find($this->filled());
        self::assertNotNull($draft);
        self::assertFalse($draft->registrationWindowContains($inside));

        $unpublished = $this->events->find($this->unpublished());
        self::assertNotNull($unpublished);
        self::assertFalse($unpublished->registrationWindowContains($inside));
    }

    public function testReadingWritesNothing(): void
    {
        $id = $this->published();
        $before = $this->row($id);

        $statements = $this->statementsOnEvents(function () use ($id): void {
            $event = $this->events->find($id);
            self::assertNotNull($event);
            $event->registrationWindowContains($this->local('2026-12-01 12:00:00'));
        });

        self::assertSame([], $statements);
        self::assertSame($before, $this->row($id));
    }

    public function testAFailedLoadMakesAPublishedEventReadAsUnpublished(): void
    {
        $id = $this->published();
        $inside = $this->local('2026-12-01 12:00:00');

        try {
            LoadState::record([new LoadFailure('x', 'y')]);
            $event = $this->events->find($id);
            self::assertNotNull($event);
            self::assertSame(EventState::Unpublished, $event->state());
            self::assertFalse($event->registrationWindowContains($inside));
            self::assertSame('published', $this->row($id)['state']);

            LoadState::record([]);
            $event = $this->events->find($id);
            self::assertNotNull($event);
            self::assertSame(EventState::Published, $event->state());
            self::assertTrue($event->registrationWindowContains($inside));
        } finally {
            LoadState::record([]);
        }
    }
}
