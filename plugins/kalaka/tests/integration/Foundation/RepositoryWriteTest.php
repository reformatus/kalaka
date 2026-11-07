<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;

/** Writes: upsert() and insert(), the version check and what the audit entry gets. */
final class RepositoryWriteTest extends RepositoryTestCase
{
    public function testUpsertOfANewEntityStoresItAndRecordsEveryColumn(): void
    {
        $stored = $this->inRun(fn (array $input, Context $context): TestItem => $this->items->upsert(
            new TestItem('First', 'a note', 'hidden', 12, '00A0'),
            $context
        ));

        self::assertInstanceOf(TestItem::class, $stored);
        self::assertGreaterThan(0, $stored->id);
        self::assertSame(1, $stored->version);
        self::assertSame('1', $this->rowOf('kalaka_test_items', $stored->id)['version'] ?? null);

        $entry = $this->onlyEntry();
        $key = (string) $stored->id;
        self::assertSame(
            [
                [
                    'type' => 'test_item', 'event' => null, 'key' => $key, 'field' => 'label',
                    'before' => null, 'after' => 'First',
                ],
                [
                    'type' => 'test_item', 'event' => null, 'key' => $key, 'field' => 'note',
                    'before' => null, 'after' => 'a note',
                ],
                ['type' => 'test_item', 'event' => null, 'key' => $key, 'field' => 'token', 'masked' => true],
                [
                    'type' => 'test_item', 'event' => null, 'key' => $key, 'field' => 'reg_event',
                    'before' => null, 'after' => '12',
                ],
                [
                    'type' => 'test_item', 'event' => null, 'key' => $key, 'field' => 'reg_code',
                    'before' => null, 'after' => '00A0',
                ],
            ],
            $this->decode($entry['changes'])
        );
        self::assertSame(['test_item//' . $key], $this->subjectsOf($entry['id']));
        self::assertStringNotContainsString('hidden', (string) $entry['changes']);
    }

    public function testUpsertOfALoadedEntityWritesOnlyTheChangedColumn(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');

        $stored = $this->inRun(function (array $input, Context $context) use ($id): TestItem {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $item->label = 'B';

            return $this->items->upsert($item, $context);
        });

        self::assertInstanceOf(TestItem::class, $stored);
        self::assertSame(2, $stored->version);
        self::assertSame('2', $this->rowOf('kalaka_test_items', $id)['version'] ?? null);
        self::assertSame(
            [[
                'type' => 'test_item', 'event' => null, 'key' => (string) $id, 'field' => 'label',
                'before' => 'A', 'after' => 'B',
            ]],
            $this->decode($this->onlyEntry()['changes'])
        );
    }

    public function testUpsertOfAnUnchangedEntityRunsNoStatementAndRecordsNothing(): void
    {
        global $wpdb;
        $id = $this->seed('kalaka_test_items', 'A');
        $touched = null;
        $statements = null;

        $this->inRun(function (array $input, Context $context) use ($id, &$touched, &$statements, $wpdb): void {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $before = $wpdb->num_queries;
            $stored = $this->items->upsert($item, $context);
            $statements = $wpdb->num_queries - $before;
            self::assertSame(1, $stored->version);
            $touched = $context->touchedRegistrations();
        });

        self::assertSame(0, $statements);
        self::assertSame([], $touched);
        $entry = $this->onlyEntry();
        self::assertSame('[]', $entry['changes']);
        self::assertSame([], $this->subjectsOf($entry['id']));
        self::assertSame('1', $this->rowOf('kalaka_test_items', $id)['version'] ?? null);
    }

    public function testAnObjectLoadedBeforeAnotherRunChangedTheRecordConflicts(): void
    {
        $id = $this->seed('kalaka_test_items', 'original');
        $stale = $this->items->find($id);
        self::assertInstanceOf(TestItem::class, $stale);

        $this->inRun(function (array $input, Context $context) use ($id): void {
            $fresh = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $fresh);
            $fresh->label = 'first run';
            self::assertSame(2, $this->items->upsert($fresh, $context)->version);
        });

        $stale->label = 'second run';
        try {
            $this->inRun(fn (array $input, Context $context) => $this->items->upsert($stale, $context));
            self::fail('The second run should have conflicted.');
        } catch (VersionConflict $conflict) {
            self::assertSame(409, $conflict->status());
        }

        self::assertSame('first run', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
        self::assertSame('2', $this->rowOf('kalaka_test_items', $id)['version'] ?? null);
    }

    public function testAnEntityCarryingAnOlderVersionThanTheRowConflictsAndWritesNothing(): void
    {
        $id = $this->seed('kalaka_test_items', 'original', 2);

        try {
            $this->inRun(function (array $input, Context $context) use ($id): void {
                $item = $this->items->find($id);
                self::assertInstanceOf(TestItem::class, $item);
                $item->label = 'screen edit';
                $item->version = 1;
                $this->items->upsert($item, $context);
            });
            self::fail('The run should have conflicted.');
        } catch (VersionConflict) {
            self::assertSame('original', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
            self::assertSame('2', $this->rowOf('kalaka_test_items', $id)['version'] ?? null);
        }
    }

    public function testTheGivenObjectKeepsItsOldVersionAndConflictsWhenUpsertedAgain(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');

        $this->inRun(function (array $input, Context $context) use ($id): void {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $item->label = 'B';
            $stored = $this->items->upsert($item, $context);
            self::assertSame(1, $item->version);
            self::assertSame(2, $stored->version);

            $stored->label = 'C';
            self::assertSame(3, $this->items->upsert($stored, $context)->version);

            $item->label = 'D';
            $this->expectException(VersionConflict::class);
            $this->items->upsert($item, $context);
        });
    }

    public function testInsertOfAnExistingKeyThroughUpsertEndsWithASystemFailure(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');

        $this->expectException(SystemFailure::class);
        $this->inRun(fn (array $input, Context $context) => $this->items->upsert(new TestItem('B', id: $id), $context));
    }

    public function testChangingAKeyColumnIsAProgrammingError(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($id): void {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $item->id = $id + 1;
            $this->items->upsert($item, $context);
        });
    }

    public function testInsertOfAnAppendOnlyRecordGivesItsSubjectAndNoChangeItem(): void
    {
        $stored = $this->inRun(fn (array $input, Context $context): TestLog => $this->logs->insert(
            new TestLog('line one'),
            $context
        ));

        self::assertInstanceOf(TestLog::class, $stored);
        self::assertGreaterThan(0, $stored->id);
        self::assertSame('line one', $this->rowOf('kalaka_test_logs', $stored->id)['label'] ?? null);
        $entry = $this->onlyEntry();
        self::assertSame('[]', $entry['changes']);
        self::assertSame(['test_log//' . $stored->id], $this->subjectsOf($entry['id']));
    }

    public function testASecondInsertWithTheSamePrimaryKeyEndsWithASystemFailureAndStoresNothingOfIt(): void
    {
        $keyed = new TestLogRepository('kalaka_test_log_keys', false);
        $this->inRun(fn (array $input, Context $context) => $keyed->insert(new TestLog('first', id: 5), $context));

        try {
            $this->inRun(function (array $input, Context $context) use ($keyed): void {
                $keyed->insert(new TestLog('another', id: 6), $context);
                $keyed->insert(new TestLog('duplicate', id: 5), $context);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame(1, $this->countRows('kalaka_test_log_keys'));
            self::assertSame('first', $this->rowOf('kalaka_test_log_keys', 5)['label'] ?? null);
        }
    }

    public function testADuplicateKeyOnAnEntityInsertIsASystemFailureAndNotRetried(): void
    {
        $id = $this->seed('kalaka_test_items', 'first');
        $useCase = new AdminProbe(function (array $input, Context $context) use ($id): void {
            $this->items->upsert(new TestItem('duplicate', id: $id), $context);
        });

        try {
            $this->runner->run($useCase, [], new CliActor(null));
            self::fail('The run should have failed.');
        } catch (SystemFailure $caught) {
            self::assertSame(SystemFailure::class, $caught::class);
            self::assertSame(1, $useCase->calls);
        }
        self::assertSame('first', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
    }

    public function testAWriteWithAContextKeptAfterItsRunIsRefusedAndWritesNothing(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $kept = null;
        $this->inRun(static function (array $input, Context $context) use (&$kept): void {
            $kept = $context;
        });
        self::assertInstanceOf(Context::class, $kept);
        $item = $this->items->find($id);
        self::assertInstanceOf(TestItem::class, $item);
        $item->label = 'late';

        foreach (
            [
                fn () => $this->items->upsert($item, $kept),
                fn () => $this->items->upsert(new TestItem('new'), $kept),
                fn () => $this->logs->insert(new TestLog('late'), $kept),
                fn () => $this->items->findForUpdate($id, $kept),
            ] as $write
        ) {
            try {
                $write();
                self::fail('A kept context should be refused.');
            } catch (SystemFailure) {
                // Expected.
            }
        }

        self::assertSame(1, $this->countRows('kalaka_test_items'));
        self::assertSame('A', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
        self::assertSame(0, $this->countRows('kalaka_test_logs'));
    }

    public function testALoadedRecordThatANestedRunWroteConflictsWhenTheOuterRunUpsertsItsOwnCopy(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $nested = new NamedProbe(function (array $input, Context $context) use ($id): void {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $item->label = 'nested';
            $this->items->upsert($item, $context);
        });

        try {
            $this->inRun(function (array $input, Context $context) use ($id, $nested): void {
                $mine = $this->items->find($id);
                self::assertInstanceOf(TestItem::class, $mine);
                $context->run($nested, []);
                $mine->label = 'outer';
                $this->items->upsert($mine, $context);
            });
            self::fail('The outer run should have conflicted.');
        } catch (VersionConflict) {
            self::assertSame('A', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
        }
    }

    public function testLoadingTheRecordAgainAfterTheNestedRunAllowsTheWrite(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $nested = new NamedProbe(function (array $input, Context $context) use ($id): void {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            $item->note = 'nested';
            $this->items->upsert($item, $context);
        });

        $this->inRun(function (array $input, Context $context) use ($id, $nested): void {
            $context->run($nested, []);
            $again = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $again);
            $again->label = 'outer';
            self::assertSame(3, $this->items->upsert($again, $context)->version);
        });

        self::assertSame(['outer', 'nested'], [
            $this->rowOf('kalaka_test_items', $id)['label'] ?? null,
            $this->rowOf('kalaka_test_items', $id)['note'] ?? null,
        ]);
    }

    public function testASystemRunRetriesAVersionConflict(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use ($id): void {
            $item = $this->items->find($id);
            self::assertInstanceOf(TestItem::class, $item);
            if ($call === 1) {
                global $wpdb;
                $wpdb->update($wpdb->prefix . 'kalaka_test_items', ['version' => 2], ['id' => $id]);
            }
            $item->label = 'after retry';
            $this->items->upsert($item, $context);
        });

        $this->runner->run($useCase, [], new \Kalaka\Foundation\Api\Actor\SystemActor());

        self::assertSame(2, $useCase->calls);
        self::assertSame('after retry', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
    }
}
