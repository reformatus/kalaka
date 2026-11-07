<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

/** Reads: find(), the object cache, findForUpdate() and the finder for a repository's own lookups. */
final class RepositoryReadTest extends RepositoryTestCase
{
    public function testFindReturnsTheStoredRecordAndNullForAMissingKey(): void
    {
        $id = $this->seed('kalaka_test_items', 'A', 3);

        $item = $this->items->find($id);

        self::assertInstanceOf(TestItem::class, $item);
        self::assertSame(['A', 3, $id], [$item->label, $item->version, $item->id]);
        self::assertNull($this->items->find($id + 100));
    }

    public function testASecondFindRunsNoStatementAndReturnsADifferentObject(): void
    {
        global $wpdb;
        $id = $this->seed('kalaka_test_items', 'A');

        $first = $this->items->find($id);
        $before = $wpdb->num_queries;
        $second = $this->items->find($id);

        self::assertSame($before, $wpdb->num_queries);
        self::assertNotSame($first, $second);
        self::assertEquals($first, $second);

        self::assertInstanceOf(TestItem::class, $first);
        $first->label = 'changed';
        $third = $this->items->find($id);
        self::assertInstanceOf(TestItem::class, $third);
        self::assertSame('A', $third->label);
    }

    public function testAMissingKeyIsNotCached(): void
    {
        $id = $this->seed('kalaka_test_items', 'A') + 1;
        self::assertNull($this->items->find($id));

        $this->seed('kalaka_test_items', 'B');

        self::assertInstanceOf(TestItem::class, $this->items->find($id));
    }

    public function testTwoRepositoriesWithTheSameKeyEachGetTheirOwnRow(): void
    {
        $other = new TestItemRepository('kalaka_test_items_other');
        $first = $this->seed('kalaka_test_items', 'in the first table');
        $second = $this->seed('kalaka_test_items_other', 'in the second table');
        self::assertSame($first, $second);

        self::assertSame('in the first table', $this->items->find($first)?->label);
        self::assertSame('in the second table', $other->find($first)?->label);
    }

    public function testARunThatThrowsLeavesNothingInTheCache(): void
    {
        $id = $this->seed('kalaka_test_items', 'before');

        try {
            $this->inRun(function (array $input, Context $context) use ($id): void {
                $item = $this->items->find($id);
                self::assertInstanceOf(TestItem::class, $item);
                $item->label = 'during';
                $this->items->upsert($item, $context);
                throw new RuleViolation();
            });
            self::fail('The run should have thrown.');
        } catch (RuleViolation) {
            // Expected.
        }

        self::assertSame('before', $this->items->find($id)?->label);
    }

    public function testARowCachedOutsideARunIsReadAfreshInsideTheNextRun(): void
    {
        global $wpdb;
        $id = $this->seed('kalaka_test_items', 'cached');
        $this->items->find($id);
        $wpdb->update($wpdb->prefix . 'kalaka_test_items', ['label' => 'changed behind our back'], ['id' => $id]);

        $seen = $this->inRun(fn (): ?string => $this->items->find($id)?->label);

        self::assertSame('changed behind our back', $seen);
    }

    public function testFindForUpdateSendsForUpdateAndReadsPastTheCache(): void
    {
        global $wpdb;
        $id = $this->seed('kalaka_test_items', 'old');

        $seen = null;
        $missing = 'not null';
        $statements = $this->statementsDuring(function () use ($id, &$seen, &$missing, $wpdb): void {
            $this->inRun(function (array $input, Context $context) use ($id, &$seen, &$missing, $wpdb): void {
                $this->items->find($id);
                $wpdb->update($wpdb->prefix . 'kalaka_test_items', ['label' => 'new'], ['id' => $id]);
                $seen = $this->items->findForUpdate($id, $context)?->label;
                $missing = $this->items->findForUpdate($id + 100, $context);
            });
        });

        self::assertSame('new', $seen);
        self::assertNull($missing);
        $locking = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_ends_with($sql, 'FOR UPDATE')
        ));
        self::assertCount(2, $locking);
        self::assertStringContainsString('kalaka_test_items', $locking[0]);
    }

    public function testFindForUpdateAfterAHigherLockLevelEndsTheRunWithoutWritingAnything(): void
    {
        $other = new TestItemRepository('kalaka_test_items', LockLevel::Other);
        $event = new TestItemRepository('kalaka_test_items_other', LockLevel::Event);
        $first = $this->seed('kalaka_test_items', 'A');
        $second = $this->seed('kalaka_test_items_other', 'B');

        try {
            $this->runner->run(new AdminProbe(function (
                array $input,
                Context $context
            ) use (
                $other,
                $event,
                $first,
                $second
            ): void {
                $this->insertRow('written');
                $other->findForUpdate($first, $context);
                $event->findForUpdate($second, $context);
            }), [], new CliActor(null));
            self::fail('The run should have thrown.');
        } catch (SystemFailure) {
            self::assertSame([], $this->rows());
        }
    }

    public function testTheFinderReturnsObjectsThatAreChangedAndUpsertedAsUpdates(): void
    {
        $id = $this->seed('kalaka_test_items', 'wanted');
        $this->seed('kalaka_test_items', 'other');

        $this->inRun(function (array $input, Context $context): void {
            $found = $this->items->byLabel('wanted');
            self::assertCount(1, $found);
            $found[0]->label = 'renamed';
            $stored = $this->items->upsert($found[0], $context);
            self::assertSame(2, $stored->version);
        });

        self::assertSame(2, $this->countRows('kalaka_test_items'));
        self::assertSame('renamed', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
        self::assertSame('2', $this->rowOf('kalaka_test_items', $id)['version'] ?? null);
    }

    public function testTheFinderLocksAtTheRepositoryLevelWhenGivenAContext(): void
    {
        $this->seed('kalaka_test_items', 'wanted');
        $event = new TestItemRepository('kalaka_test_items', LockLevel::Event);

        $statements = $this->statementsDuring(function () use ($event): void {
            $this->inRun(function (array $input, Context $context) use ($event): void {
                self::assertCount(1, $event->byLabelForUpdate('wanted', $context));
            });
        });

        self::assertNotEmpty(array_filter(
            $statements,
            static fn (string $sql): bool => str_ends_with($sql, 'FOR UPDATE')
        ));
    }
}
