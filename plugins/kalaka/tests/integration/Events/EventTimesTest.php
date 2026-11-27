<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventTimes;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** N1190 and N1191: `EventTimes::save()` runs the update through the runner as the current user. */
final class EventTimesTest extends EventsTestCase
{
    public function testItSavesTheStartAndEndOfAUserWithFullAccess(): void
    {
        $id = $this->filled();
        $this->update($id, ['points' => [['name' => 'Befizetés', 'at' => '2027-05-01 10:00']]]);
        wp_set_current_user($this->fullAccessUser());
        $event = $this->events->find($id);
        self::assertNotNull($event);

        $saved = (new EventTimes($this->runner))->save($event, '2027-07-06 09:00', '2027-07-13 16:30', $event->version);

        $this->assertUtc('2027-07-06 07:00:00', $saved->start);
        $this->assertUtc('2027-07-13 14:30:00', $saved->end);
        self::assertSame($event->version + 1, $saved->version);
        // everything else is as it was
        self::assertSame('Tábor', $saved->name);
        self::assertSame(41, $saved->capacity);
        $this->assertUtc('2026-11-30 07:00:00', $saved->registrationOpens);
        $this->assertUtc('2026-12-15 19:00:00', $saved->registrationCloses);
        self::assertSame(['Befizetés'], array_map(static fn ($point): string => $point->name, $saved->customPoints));
        $this->assertUtc('2027-05-01 08:00:00', $saved->customPoints[0]->at);
    }

    public function testAUserWithoutFullAccessIsRefusedByTheUseCaseAndNothingChanges(): void
    {
        $id = $this->filled();
        $before = $this->row($id);
        wp_set_current_user(self::factory()->user->create());
        $event = $this->events->find($id);
        self::assertNotNull($event);

        try {
            (new EventTimes($this->runner))->save($event, '2027-07-06 09:00', '2027-07-13 16:30', $event->version);
            self::fail('The save should have been refused.');
        } catch (PermissionDenied) {
            self::assertSame($before, $this->row($id));
        }
    }

    public function testAStaleVersionConflictsAndNothingChanges(): void
    {
        $id = $this->filled();
        wp_set_current_user($this->fullAccessUser());
        $event = $this->events->find($id);
        self::assertNotNull($event);
        $this->update($id, ['name' => 'Másik']);
        $before = $this->row($id);

        try {
            (new EventTimes($this->runner))->save($event, '2027-07-06 09:00', '2027-07-13 16:30', $event->version);
            self::fail('The stale version should have conflicted.');
        } catch (VersionConflict) {
            self::assertSame($before, $this->row($id));
        }
    }

    private function assertUtc(string $expected, ?\DateTimeImmutable $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame($expected, $actual->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }
}
