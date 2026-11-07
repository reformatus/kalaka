<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventState;
use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** AC2. */
final class CreateEventTest extends EventsTestCase
{
    public function testANameAloneMakesADraftWithNoPointsAndNoCapacity(): void
    {
        $id = $this->create('Nyári tábor');

        $row = $this->row($id);
        self::assertSame(['1', 'Nyári tábor', 'draft', null, '0', '[]', '1', null], [
            $row['version'], $row['name'], $row['state'], $row['capacity'], $row['is_full'],
            $row['points'], $row['next_point_id'], $row['archived_at'],
        ]);

        $event = $this->events->find($id);
        self::assertNotNull($event);
        self::assertSame(EventState::Draft, $event->state());
        self::assertSame([], $event->customPoints);

        $entry = $this->onlyEntry();
        self::assertSame("event/{$id}/{$id}", $this->subjectsOf($entry['id'])[0]);
        self::assertCount(1, $this->subjectsOf($entry['id']));
        self::assertSame(
            [
                ['name', null, 'Nyári tábor'],
                ['state', null, 'draft'],
                ['is_full', null, '0'],
                ['points', null, '[]'],
                ['next_point_id', null, '1'],
            ],
            array_map(
                static fn (array $change): array => [$change['field'], $change['before'], $change['after']],
                $this->changesOf($entry)
            )
        );
        self::assertSame($id, (int) $this->changesOf($entry)[0]['key']);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ["  \t "];
        yield '192 characters' => [str_repeat('á', 192)];
    }

    /** @dataProvider badNames */
    public function testAnEmptyBlankOrTooLongNameIsRefusedWithNoRowAndNoEntry(string $name): void
    {
        $before = $this->eventCount();

        try {
            $this->exec(new CreateEvent(), ['name' => $name]);
            self::fail('The name should have been refused.');
        } catch (InvalidInput) {
            self::assertSame($before, $this->eventCount());
            self::assertSame([], $this->entries());
        }
    }

    public function testANameOf191CharactersIsStoredTrimmed(): void
    {
        $id = $this->create(' ' . str_repeat('á', 191) . ' ');

        self::assertSame(str_repeat('á', 191), $this->row($id)['name']);
    }
}
