<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;

require_once __DIR__ . '/EventsTestCase.php';

/** AC3 and AC4. */
final class UpdateEventTest extends EventsTestCase
{
    public function testTheApiReturnsWhatWasSet(): void
    {
        $id = $this->create();
        $this->update($id, [
            'name' => 'Őszi tábor',
            'points' => [
                ['name' => 'Befizetés', 'date' => '2027-05-01'],
                ['name' => 'Csomaglista', 'date' => '2027-06-01'],
            ],
        ]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(2, $event->version);
        self::assertSame('Őszi tábor', $event->name);
        self::assertSame(41, $event->capacity);
        self::assertFalse($event->isFull);
        $zone = new \DateTimeZone('Europe/Budapest');
        self::assertEquals(new \DateTimeImmutable('2026-11-30 00:00', $zone), $event->registrationOpens);
        self::assertEquals(new \DateTimeImmutable('2026-12-15 00:00', $zone), $event->registrationCloses);
        self::assertEquals(new \DateTimeImmutable('2027-07-05 00:00', $zone), $event->start);
        self::assertEquals(new \DateTimeImmutable('2027-07-12 00:00', $zone), $event->end);
        self::assertSame([1, 2], array_map(static fn ($point): int => $point->id, $event->customPoints));
        self::assertSame(['Befizetés', 'Csomaglista'], array_map(static fn ($point): string => $point->name, $event->customPoints));
        self::assertEquals(new \DateTimeImmutable('2027-06-01 00:00', $zone), $event->customPoints[1]->day);
        self::assertSame(
            '[{"id":1,"name":"Befizetés","date":"2027-05-01"},{"id":2,"name":"Csomaglista","date":"2027-06-01"}]',
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
        yield 'registration opens after it closes' => [['registration_opens_on' => '2026-12-16']];
        yield 'start after end' => [['starts_on' => '2027-07-13']];
        yield 'a day that is not on the calendar' => [['ends_on' => '2027-02-30']];
        yield 'point names equal after trimming and lowercasing' => [['points' => [
            ['name' => 'Árvíz', 'date' => '2027-05-01'],
            ['name' => ' árvíz ', 'date' => '2027-05-02'],
        ]]];
        yield 'an empty point name' => [['points' => [['name' => '  ', 'date' => '2027-05-01']]]];
        yield 'a 192-character point name' => [['points' => [['name' => str_repeat('b', 192), 'date' => '2027-05-01']]]];
        yield 'a point id that is not stored' => [['points' => [['id' => 99, 'name' => 'Új', 'date' => '2027-05-01']]]];
        yield 'a point id given twice' => [['points' => [
            ['id' => 1, 'name' => 'Egy', 'date' => '2027-05-01'],
            ['id' => 1, 'name' => 'Kettő', 'date' => '2027-05-02'],
        ]]];
    }

    /**
     * @param array<string, mixed> $overrides
     * @dataProvider invalidInputs
     */
    public function testAnInvalidInputIsRefusedAndChangesNothing(array $overrides): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Egy', 'date' => '2027-05-01']]]);
        $before = $this->row($id);

        try {
            $this->exec(new UpdateEvent(), $this->updateInput($id, 2, $overrides));
            self::fail('The input should have been refused.');
        } catch (InvalidInput) {
            self::assertSame($before, $this->row($id));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsettable(): iterable
    {
        yield 'capacity' => ['capacity'];
        yield 'registration opens' => ['registration_opens_on'];
        yield 'registration closes' => ['registration_closes_on'];
        yield 'start' => ['starts_on'];
        yield 'end' => ['ends_on'];
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

        $this->update($id, ['capacity' => null, 'starts_on' => null]);

        self::assertNull($this->row($id)['capacity']);
        self::assertNull($this->row($id)['starts_on']);
    }

    public function testChangingOnlyTheCustomPointsBumpsTheVersionOnceAndListsTheChange(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Egy', 'date' => '2027-05-01']]]);
        $this->update($id, ['points' => [['id' => 1, 'name' => 'Egy', 'date' => '2027-05-02']]]);

        self::assertSame(3, $this->versionOf($id));
        $entry = $this->entriesOf('kalaka/update-event')[1];
        self::assertSame(['points'], $this->changedFields($entry));
        $change = $this->changesOf($entry)[0];
        self::assertSame('[{"id":1,"name":"Egy","date":"2027-05-01"}]', $change['before']);
        self::assertSame('[{"id":1,"name":"Egy","date":"2027-05-02"}]', $change['after']);
    }

    public function testPointIdsAreNeverReused(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [
            ['name' => 'Egy', 'date' => '2027-05-01'],
            ['name' => 'Kettő', 'date' => '2027-05-02'],
        ]]);
        $this->update($id, ['points' => [
            ['id' => 1, 'name' => 'Egy', 'date' => '2027-05-01'],
            ['name' => 'Három', 'date' => '2027-05-03'],
        ]]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame([1, 3], array_map(static fn ($point): int => $point->id, $event->customPoints));
        self::assertSame('4', $this->row($id)['next_point_id']);

        $this->expectException(InvalidInput::class);
        $this->update($id, ['points' => [['id' => 2, 'name' => 'Kettő', 'date' => '2027-05-02']]]);
    }

    public function testResendingTheStoredValuesChangesNothingAndRunsNoUpdate(): void
    {
        $id = $this->create();
        $this->update($id, ['points' => [['name' => 'Árvíz', 'date' => '2027-05-01']]]);
        $before = $this->row($id);

        $statements = $this->statementsOnEvents(fn () => $this->update(
            $id,
            ['points' => [['id' => 1, 'name' => 'Árvíz', 'date' => '2027-05-01']]]
        ));

        self::assertSame([], $statements);
        self::assertSame($before, $this->row($id));
        $entry = $this->entriesOf('kalaka/update-event')[1];
        self::assertSame('[]', $entry['changes']);
        self::assertSame(["event/{$id}/{$id}"], $this->subjectsOf($entry['id']));
    }

    public function testEditingTheCapacityOrThePointsOfAFullEventKeepsTheLatch(): void
    {
        $id = $this->filled();
        $this->markFull($id);

        $this->update($id, ['capacity' => 45]);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(45, $event->capacity);
        self::assertTrue($event->isFull);

        $this->update($id, ['registration_opens_on' => '2026-12-01', 'registration_closes_on' => '2027-01-15']);
        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertTrue($event->isFull);
        self::assertSame('1', $this->row($id)['is_full']);
    }
}
