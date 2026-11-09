<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** AC3 and AC4. */
final class UpdateEventTest extends EventsTestCase
{
    public function testTheApiReturnsWhatWasSet(): void
    {
        $id = $this->create();
        $this->update($id, [
            'name' => 'Őszi tábor',
            'points' => [
                ['name' => 'Befizetés', 'at' => '2027-05-01 10:00'],
                ['name' => 'Csomaglista', 'at' => '2027-06-01 10:00'],
            ],
        ]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(2, $event->version);
        self::assertSame('Őszi tábor', $event->name);
        self::assertSame(41, $event->capacity);
        self::assertFalse($event->isFull);
        $this->assertUtc('2026-11-30 07:00:00', $event->registrationOpens);
        $this->assertUtc('2026-12-15 19:00:00', $event->registrationCloses);
        $this->assertUtc('2027-07-05 08:00:00', $event->start);
        $this->assertUtc('2027-07-12 12:00:00', $event->end);
        self::assertSame([1, 2], array_map(static fn ($point): int => $point->id, $event->customPoints));
        self::assertSame(
            ['Befizetés', 'Csomaglista'],
            array_map(static fn ($point): string => $point->name, $event->customPoints)
        );
        $this->assertUtc('2027-06-01 08:00:00', $event->customPoints[1]->at);
        self::assertSame(
            '[{"id":1,"name":"Befizetés","at":"2027-05-01 08:00:00.000000"},'
            . '{"id":2,"name":"Csomaglista","at":"2027-06-01 08:00:00.000000"}]',
            $this->row($id)['points']
        );
        self::assertSame('3', $this->row($id)['next_point_id']);
    }

    public function testASecondUpdateWithTheOldVersionConflictsAndChangesNothing(): void
    {
        $id = $this->create();
        $this->exec(new UpdateEvent(), $this->updateInput($id, 1));
        $before = $this->row($id);

        try {
            $this->exec(new UpdateEvent(), $this->updateInput($id, 1, ['name' => 'Másik', 'capacity' => 50]));
            self::fail('The stale version should have conflicted.');
        } catch (VersionConflict) {
            self::assertSame($before, $this->row($id));
            self::assertCount(1, $this->failedEntries());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'registration opens after it closes' => [['registration_opens_at' => '2026-12-16 10:00']];
        yield 'start after end' => [['starts_at' => '2027-07-13 15:00']];
        yield 'a day that is not on the calendar' => [['ends_at' => '2027-02-30 10:00']];
        yield 'a day without a time' => [['starts_at' => '2027-07-05']];
        yield 'a time with seconds' => [['starts_at' => '2027-07-05 10:00:30']];
        yield 'an hour of 25' => [['starts_at' => '2027-07-05 25:00']];
        yield 'a local time the zone skips' => [['starts_at' => '2027-03-28 02:30', 'ends_at' => '2027-03-29 10:00']];
        yield 'a custom point without a time' => [['points' => [['name' => 'Egy', 'at' => '2027-05-01']]]];
        yield 'registration closes before it opens on the same day' => [
            ['registration_opens_at' => '2026-12-15 20:01'],
        ];
        yield 'point names equal after trimming and lowercasing' => [['points' => [
            ['name' => 'Árvíz', 'at' => '2027-05-01 10:00'],
            ['name' => ' árvíz ', 'at' => '2027-05-02 10:00'],
        ]]];
        yield 'an empty point name' => [['points' => [['name' => '  ', 'at' => '2027-05-01 10:00']]]];
        yield 'a 192-character point name' => [
            ['points' => [['name' => str_repeat('b', 192), 'at' => '2027-05-01 10:00']]],
        ];
        yield 'a point id that is not stored' => [
            ['points' => [['id' => 99, 'name' => 'Új', 'at' => '2027-05-01 10:00']]],
        ];
        yield 'a capacity over the limit' => [['capacity' => 50001]];
        yield 'a capacity over the column' => [['capacity' => 4294967296]];
        yield 'a point id given twice' => [['points' => [
            ['id' => 1, 'name' => 'Egy', 'at' => '2027-05-01 10:00'],
            ['id' => 1, 'name' => 'Kettő', 'at' => '2027-05-02 10:00'],
        ]]];
    }

    /**
     * @param array<string, mixed> $overrides
     * @dataProvider invalidInputs
     */
    public function testAnInvalidInputIsRefusedAndChangesNothing(array $overrides): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Egy', 'at' => '2027-05-01 10:00']]]);
        $before = $this->row($id);

        try {
            $this->exec(new UpdateEvent(), $this->updateInput($id, 2, $overrides));
            self::fail('The input should have been refused.');
        } catch (InvalidInput) {
            self::assertSame($before, $this->row($id));
        }
    }

    public function testTheLargestAllowedCapacityIsSaved(): void
    {
        $id = $this->create();
        $this->update($id, ['capacity' => 50000]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(50000, $event->capacity);
    }

    /** @return iterable<string, array{string}> */
    public static function unsettable(): iterable
    {
        yield 'capacity' => ['capacity'];
        yield 'registration opens' => ['registration_opens_at'];
        yield 'registration closes' => ['registration_closes_at'];
        yield 'start' => ['starts_at'];
        yield 'end' => ['ends_at'];
    }

    /** @dataProvider unsettable */
    public function testAPublishedEventCannotLoseACorePointOrItsCapacity(string $field): void
    {
        $id = $this->published();
        $before = $this->row($id);

        try {
            $this->exec(new UpdateEvent(), $this->updateInput($id, $this->versionOf($id), [$field => null]));
            self::fail('The update should have been refused.');
        } catch (RuleViolation) {
            self::assertSame($before, $this->row($id));
            self::assertCount(1, $this->failedEntries());
        }
    }

    public function testADraftMayLoseThem(): void
    {
        $id = $this->filled();

        $this->update($id, ['capacity' => null, 'starts_at' => null]);

        self::assertNull($this->row($id)['capacity']);
        self::assertNull($this->row($id)['starts_at']);
    }

    public function testChangingOnlyTheCustomPointsBumpsTheVersionOnceAndListsTheChange(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Egy', 'at' => '2027-05-01 10:00']]]);
        $this->update($id, ['points' => [['id' => 1, 'name' => 'Egy', 'at' => '2027-05-02 10:00']]]);

        self::assertSame(3, $this->versionOf($id));
        $entry = $this->entriesOf('kalaka/update-event')[1];
        self::assertSame(['points'], $this->changedFields($entry));
        $change = $this->changesOf($entry)[0];
        self::assertSame('[{"id":1,"name":"Egy","at":"2027-05-01 08:00:00.000000"}]', $change['before']);
        self::assertSame('[{"id":1,"name":"Egy","at":"2027-05-02 08:00:00.000000"}]', $change['after']);
    }

    public function testPointIdsAreNeverReused(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [
            ['name' => 'Egy', 'at' => '2027-05-01 10:00'],
            ['name' => 'Kettő', 'at' => '2027-05-02 10:00'],
        ]]);
        $this->update($id, ['points' => [
            ['id' => 1, 'name' => 'Egy', 'at' => '2027-05-01 10:00'],
            ['name' => 'Három', 'at' => '2027-05-03 10:00'],
        ]]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame([1, 3], array_map(static fn ($point): int => $point->id, $event->customPoints));
        self::assertSame('4', $this->row($id)['next_point_id']);

        $this->expectException(InvalidInput::class);
        $this->update($id, ['points' => [['id' => 2, 'name' => 'Kettő', 'at' => '2027-05-02 10:00']]]);
    }

    public function testResendingTheStoredValuesWithAnOldVersionConflictsAndWritesNothing(): void
    {
        $id = $this->filled();
        $before = $this->row($id);
        self::assertSame('2', $before['version'] ?? null);

        try {
            $this->exec(new UpdateEvent(), $this->updateInput($id, 1));
            self::fail('The stale version should have conflicted.');
        } catch (VersionConflict) {
            self::assertSame($before, $this->row($id));
            self::assertCount(1, $this->failedEntries());
        }
    }

    public function testResendingTheStoredValuesChangesNothingAndRunsNoUpdate(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Árvíz', 'at' => '2027-05-01 10:00']]]);
        $before = $this->row($id);

        $statements = $this->statementsOnEvents(fn () => $this->update(
            $id,
            ['points' => [['id' => 1, 'name' => 'Árvíz', 'at' => '2027-05-01 10:00']]]
        ));

        self::assertSame([], $statements);
        self::assertSame($before, $this->row($id));
        $entry = $this->entriesOf('kalaka/update-event')[1];
        self::assertSame('[]', $entry['changes']);
        self::assertSame(["event/{$id}/{$id}"], $this->subjectsOf($entry['id']));
    }

    public function testEditingTheCapacityOrThePointsOfAFullEventKeepsTheLatch(): void
    {
        $id = $this->published();
        $this->markFull($id);

        $this->update($id, ['capacity' => 45]);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(45, $event->capacity);
        self::assertTrue($event->isFull);

        $this->update(
            $id,
            ['registration_opens_at' => '2026-12-01 10:00', 'registration_closes_at' => '2027-01-15 10:00']
        );
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertTrue($event->isFull);
        self::assertSame('1', $this->row($id)['is_full']);
    }

    public function testChangingTheSiteZoneAfterwardsMovesNoPoint(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Egy', 'at' => '2026-07-01 08:00']]]);
        $before = $this->row($id);

        update_option('timezone_string', 'UTC');
        $event = $this->events->find($id);

        self::assertNotNull($event);
        $this->assertUtc('2026-11-30 07:00:00', $event->registrationOpens);
        $this->assertUtc('2026-07-01 06:00:00', $event->customPoints[0]->at);
        self::assertSame($before, $this->row($id));
    }

    public function testTheStoredPointsAreUtcDatetimes(): void
    {
        $id = $this->create();
        $this->update($id);

        $row = $this->row($id);
        self::assertSame('2026-11-30 07:00:00.000000', $row['registration_opens_at']);
        self::assertSame('2026-12-15 19:00:00.000000', $row['registration_closes_at']);
    }

    public function testAnAmbiguousLocalTimeIsTheEarlierInstant(): void
    {
        $id = $this->create();
        $this->update($id, ['starts_at' => '2026-10-25 02:30', 'ends_at' => '2026-10-26 10:00']);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        $this->assertUtc('2026-10-25 00:30:00', $event->start);
    }

    private function assertUtc(string $expected, ?\DateTimeImmutable $actual): void
    {
        self::assertNotNull($actual);
        self::assertSame($expected, $actual->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }
}
