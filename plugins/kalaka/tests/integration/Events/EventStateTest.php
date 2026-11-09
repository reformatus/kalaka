<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\ReopenEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\UseCase;

/** AC5, AC6 and AC9. */
final class EventStateTest extends EventsTestCase
{
    public function testEachAllowedTransitionChangesOnlyTheState(): void
    {
        $id = $this->filled();

        $this->transition(new PublishEvent(), $id);
        self::assertSame('published', $this->row($id)['state']);
        $this->transition(new UnpublishEvent(), $id);
        self::assertSame('unpublished', $this->row($id)['state']);
        $this->transition(new PublishEvent(), $id);
        self::assertSame('published', $this->row($id)['state']);
        $this->transition(new UnpublishEvent(), $id);

        foreach (['kalaka/publish-event', 'kalaka/unpublish-event'] as $action) {
            foreach ($this->entriesOf($action) as $entry) {
                self::assertSame(['state'], $this->changedFields($entry));
            }
        }

        $this->transition(new ArchiveEvent(), $id);
        self::assertSame('archived', $this->row($id)['state']);
        self::assertSame(['state', 'archived_at'], $this->changedFields($this->entriesOf('kalaka/archive-event')[0]));
    }

    /** @return iterable<string, array{string, class-string<UseCase>}> */
    public static function refusedTransitions(): iterable
    {
        yield 'draft to unpublished' => ['draft', UnpublishEvent::class];
        yield 'draft to archived' => ['draft', ArchiveEvent::class];
        yield 'published to published' => ['published', PublishEvent::class];
        yield 'published to archived' => ['published', ArchiveEvent::class];
        yield 'unpublished to unpublished' => ['unpublished', UnpublishEvent::class];
    }

    /**
     * @param class-string<UseCase> $useCase
     * @dataProvider refusedTransitions
     */
    public function testAnyOtherTransitionIsRefusedWithTheDefaultText(string $from, string $useCase): void
    {
        $id = match ($from) {
            'draft' => $this->filled(),
            'published' => $this->published(),
            default => $this->unpublished(),
        };
        $before = $this->row($id);

        try {
            $this->transition(new $useCase(), $id);
            self::fail('The transition should have been refused.');
        } catch (RuleViolation $error) {
            self::assertSame((new RuleViolation())->getMessage(), $error->getMessage());
            self::assertSame($before, $this->row($id));
            self::assertCount(1, $this->failedEntries());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function missingForPublishing(): iterable
    {
        yield 'capacity' => ['capacity'];
        yield 'registration opens' => ['registration_opens_at'];
        yield 'registration closes' => ['registration_closes_at'];
        yield 'start' => ['starts_at'];
        yield 'end' => ['ends_at'];
    }

    /** @dataProvider missingForPublishing */
    public function testADraftLackingACorePointOrTheCapacityCannotBePublished(string $field): void
    {
        $id = $this->create();
        $this->update($id, [$field => null]);

        $this->expectException(RuleViolation::class);
        $this->transition(new PublishEvent(), $id);
    }

    public function testADraftWithNothingSetCannotBePublished(): void
    {
        $id = $this->create();

        $this->expectException(RuleViolation::class);
        $this->transition(new PublishEvent(), $id);
    }

    public function testArchivedIsFinalAndReadOnly(): void
    {
        $this->useClock($this->clockAt('2026-12-01 10:30:00'));
        // Only a published event takes the latch.
        $id = $this->published();
        $this->markFull($id);
        $this->transition(new UnpublishEvent(), $id);
        $this->transition(new ArchiveEvent(), $id);
        self::assertSame('2026-12-01 09:30:00.000000', $this->row($id)['archived_at']);
        self::assertSame('1', $this->row($id)['is_full']);
        $before = $this->row($id);

        $attempts = [
            [new UpdateEvent(), $this->updateInput($id, (int) $before['version'], ['name' => 'Másik'])],
            [new PublishEvent(), ['id' => $id, 'version' => (int) $before['version']]],
            [new UnpublishEvent(), ['id' => $id, 'version' => (int) $before['version']]],
            [new ArchiveEvent(), ['id' => $id, 'version' => (int) $before['version']]],
            [new ReopenEvent(), ['id' => $id, 'version' => (int) $before['version']]],
        ];
        foreach ($attempts as [$useCase, $input]) {
            try {
                $this->exec($useCase, $input);
                self::fail($useCase::name() . ' should have been refused.');
            } catch (RuleViolation) {
                self::assertSame($before, $this->row($id));
            }
        }
        self::assertCount(5, $this->failedEntries());
    }

    public function testReopeningAFullEventClearsTheLatch(): void
    {
        $id = $this->published();
        $this->markFull($id);

        $this->transition(new ReopenEvent(), $id);

        self::assertSame('0', $this->row($id)['is_full']);
        self::assertSame('published', $this->row($id)['state']);
        $entry = $this->entriesOf('kalaka/reopen-event')[0];
        self::assertSame(
            [
                [
                    'type' => 'event',
                    'event' => $id,
                    'key' => (string) $id,
                    'field' => 'is_full',
                    'before' => '1',
                    'after' => '0',
                ],
            ],
            $this->changesOf($entry)
        );
    }

    public function testReopeningAnEventThatIsNotFullIsRefused(): void
    {
        $id = $this->published();

        $this->expectException(RuleViolation::class);
        $this->transition(new ReopenEvent(), $id);
    }

    public function testChangingTheStateNeverTouchesTheLatch(): void
    {
        $id = $this->published();
        $this->markFull($id);

        $this->transition(new UnpublishEvent(), $id);
        $this->transition(new PublishEvent(), $id);

        self::assertSame('1', $this->row($id)['is_full']);
    }
}
