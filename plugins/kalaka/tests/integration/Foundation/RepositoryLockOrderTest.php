<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

/** The lock-order guard hears about every repository write, and checks key order within a level. */
final class RepositoryLockOrderTest extends RepositoryTestCase
{
    private function itemsAt(LockLevel $level, string $table = 'kalaka_test_items'): TestItemRepository
    {
        return new TestItemRepository($table, $level);
    }

    /** @return list<int> the ids of `$count` seeded rows, labelled by the callback */
    private function seedMany(int $count, ?\Closure $label = null): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->seed('kalaka_test_items', $label === null ? 'row' : $label($i));
        }

        return $ids;
    }

    public function testAnEntityInsertThenALowerLockIsASystemFailureAndLeavesNothing(): void
    {
        $registrationLevel = $this->itemsAt(LockLevel::Registration);

        try {
            $this->inRun(function (array $input, Context $context) use ($registrationLevel): void {
                $registrationLevel->upsert(new TestItem('new'), $context);
                $context->lock(LockLevel::Event);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame(0, $this->countRows('kalaka_test_items'));
        }
    }

    public function testAnAppendOnlyInsertThenALowerLockIsASystemFailureAndLeavesNothing(): void
    {
        $logs = new TestLogRepository('kalaka_test_logs', true, LockLevel::Quota);

        try {
            $this->inRun(function (array $input, Context $context) use ($logs): void {
                $logs->insert(new TestLog('new'), $context);
                $context->lock(LockLevel::Event);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame(0, $this->countRows('kalaka_test_logs'));
        }
    }

    public function testAnInsertBelowTheLevelReachedIsASystemFailureBeforeTheStatement(): void
    {
        $eventLevel = $this->itemsAt(LockLevel::Event);

        try {
            $this->inRun(function (array $input, Context $context) use ($eventLevel): void {
                $context->lock(LockLevel::Registration);
                $eventLevel->upsert(new TestItem('new'), $context);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame(0, $this->countRows('kalaka_test_items'));
        }
    }

    public function testAnUpdateOfAnUnheldRowDeclaresItsLevel(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $repository = $this->itemsAt(LockLevel::Registration);

        try {
            $this->inRun(function (array $input, Context $context) use ($repository, $id): void {
                $item = $repository->find($id);
                self::assertInstanceOf(TestItem::class, $item);
                $item->label = 'B';
                $repository->upsert($item, $context);
                $context->lock(LockLevel::Quota);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame('A', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
        }
    }

    public function testADeleteOfAnUnheldRowDeclaresItsLevel(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $repository = $this->itemsAt(LockLevel::Registration);

        try {
            $this->inRun(function (array $input, Context $context) use ($repository, $id): void {
                $item = $repository->find($id);
                self::assertInstanceOf(TestItem::class, $item);
                $repository->delete($item, $context);
                $context->lock(LockLevel::Quota);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertNotNull($this->rowOf('kalaka_test_items', $id));
        }
    }

    public function testAnUpdateOfAnUnheldRowBelowTheHighestKeyIsASystemFailure(): void
    {
        [$first, $second] = $this->seedMany(2);
        $repository = $this->itemsAt(LockLevel::Quota);

        try {
            $this->inRun(function (array $input, Context $context) use ($repository, $first, $second): void {
                $repository->findForUpdate($second, $context);
                $item = $repository->find($first);
                self::assertInstanceOf(TestItem::class, $item);
                $item->label = 'B';
                $repository->upsert($item, $context);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame('row', $this->rowOf('kalaka_test_items', $first)['label'] ?? null);
        }
    }

    public function testARowAlreadyHeldCanBeWrittenAfterAHigherLevel(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $event = $this->itemsAt(LockLevel::Event);
        $registration = $this->itemsAt(LockLevel::Registration, 'kalaka_test_items_other');

        $this->inRun(function (array $input, Context $context) use ($event, $registration, $id): void {
            $held = $event->findForUpdate($id, $context);
            self::assertInstanceOf(TestItem::class, $held);
            $registration->upsert(new TestItem('new'), $context);
            $held->label = 'B';
            $event->upsert($held, $context);
        });

        self::assertSame('B', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
    }

    public function testTwoRowsOfAKeyOrderedLevelInDescendingOrderAreASystemFailureNamingBoth(): void
    {
        [$first, $second] = $this->seedMany(2);
        $repository = $this->itemsAt(LockLevel::Quota);

        try {
            $this->inRun(function (array $input, Context $context) use ($repository, $first, $second): void {
                $repository->findForUpdate($second, $context);
                $repository->findForUpdate($first, $context);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure $failure) {
            self::assertInstanceOf(\LogicException::class, $failure->getPrevious());
            self::assertSame(
                "Lock order violated: Quota row kalaka_test_items ({$first})"
                . " requested after kalaka_test_items ({$second}).",
                $failure->getPrevious()->getMessage()
            );
        }
    }

    public function testAscendingOrderAndTheSameRowTwicePass(): void
    {
        [$first, $second] = $this->seedMany(2);
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (array $input, Context $context) use ($repository, $first, $second): void {
            $repository->findForUpdate($first, $context);
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($first, $context);
        });

        self::addToAssertionCount(1);
    }

    public function testTablesOfOneLevelAreOrderedByTableNameFirst(): void
    {
        $a = $this->seed('kalaka_test_items', 'A');
        $b = $this->seed('kalaka_test_items_other', 'B');
        $items = $this->itemsAt(LockLevel::Quota);
        $other = $this->itemsAt(LockLevel::Quota, 'kalaka_test_items_other');

        $this->inRun(function (array $input, Context $context) use ($items, $other, $a, $b): void {
            $items->findForUpdate($a, $context);
            $other->findForUpdate($b, $context);
        });

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($items, $other, $a, $b): void {
            $other->findForUpdate($b, $context);
            $items->findForUpdate($a, $context);
        });
    }

    public function testAtLevelOtherAnyKeyOrderPasses(): void
    {
        [$first, $second] = $this->seedMany(2);
        $repository = $this->itemsAt(LockLevel::Other);

        $this->inRun(function (array $input, Context $context) use ($repository, $first, $second): void {
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($first, $context);
        });

        self::addToAssertionCount(1);
    }

    public function testAnInsertAtTheLevelOfAHeldRowPasses(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (array $input, Context $context) use ($repository, $id): void {
            $repository->findForUpdate($id, $context);
            $repository->upsert(new TestItem('new'), $context);
        });

        self::assertSame(2, $this->countRows('kalaka_test_items'));
    }

    public function testAnInsertedRowCountsAsHeld(): void
    {
        $repository = $this->itemsAt(LockLevel::Event);

        $this->inRun(function (array $input, Context $context) use ($repository): void {
            $stored = $repository->upsert(new TestItem('new'), $context);
            $context->lock(LockLevel::Registration);
            $stored->label = 'B';
            $repository->upsert($stored, $context);
        });

        self::assertSame(1, $this->countRows('kalaka_test_items'));
    }

    public function testAFindWhereForUpdateRecordsEveryReturnedRowAsHeldWhateverTheTypeOfItsKey(): void
    {
        [$first, $second] = $this->seedMany(2, static fn (): string => 'same');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (array $input, Context $context) use ($repository, $first, $second): void {
            // The rows come back with string keys; the finder below gives int keys.
            $repository->byLabelForUpdate('same', $context);
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($first, $context);
        });

        self::addToAssertionCount(1);
    }

    public function testAFindWhereForUpdateWhoseLowestRowIsBelowAHeldKeyIsASystemFailure(): void
    {
        $ids = $this->seedMany(7, static fn (int $i): string => in_array($i, [3, 7], true) ? 'pick' : 'row');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($repository, $ids): void {
            $repository->findForUpdate($ids[4], $context);
            $repository->byLabelForUpdate('pick', $context);
        });
    }

    public function testAFindWhereForUpdateAboveTheHeldKeyPassesAndRaisesTheHighestKey(): void
    {
        $ids = $this->seedMany(8, static fn (int $i): string => in_array($i, [6, 8], true) ? 'pick' : 'row');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (array $input, Context $context) use ($repository, $ids): void {
            $repository->findForUpdate($ids[4], $context);
            $repository->byLabelForUpdate('pick', $context);
            $repository->findForUpdate($ids[5], $context);
        });

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($repository, $ids): void {
            $repository->findForUpdate($ids[4], $context);
            $repository->byLabelForUpdate('pick', $context);
            $repository->findForUpdate($ids[6], $context);
        });
    }

    public function testAFindWhereForUpdateRefusesANewRowBelowTheHighestKeyEvenWhenItsLowestRowIsHeld(): void
    {
        $ids = $this->seedMany(7, static fn (int $i): string => in_array($i, [3, 5], true) ? 'pick' : 'row');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($repository, $ids): void {
            $repository->findForUpdate($ids[2], $context);
            $repository->findForUpdate($ids[6], $context);
            // Returns 3, held, and 5, new and below 7.
            $repository->byLabelForUpdate('pick', $context);
        });
    }

    public function testKeysCompareAsNumbersWhetherReadAsStringsOrGivenAsInts(): void
    {
        $ids = $this->seedMany(10, static fn (int $i): string => 'row ' . $i);
        self::assertSame(9, $ids[8]);
        self::assertSame(10, $ids[9]);
        $repository = $this->itemsAt(LockLevel::Quota);

        // '9' read back, then 10 given: ascending.
        $this->inRun(function (array $input, Context $context) use ($repository): void {
            $repository->byLabelForUpdate('row 9', $context);
            $repository->findForUpdate(10, $context);
        });

        // '10' read back, then 9 given: descending, since 10 > 9.
        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($repository): void {
            $repository->byLabelForUpdate('row 10', $context);
            $repository->findForUpdate(9, $context);
        });
    }

    public function testANestedRunSharesTheHeldRowsAndKeysOfTheOuterRun(): void
    {
        [$first, $second] = $this->seedMany(2);
        $repository = $this->itemsAt(LockLevel::Quota);
        $relock = new AdminProbe(function (array $input, Context $context) use ($repository, $second): void {
            $repository->findForUpdate($second, $context);
        });
        $lower = new AdminProbe(function (array $input, Context $context) use ($repository, $first): void {
            $repository->findForUpdate($first, $context);
        });

        $this->inRun(function (array $input, Context $context) use ($repository, $second, $relock): void {
            $repository->findForUpdate($second, $context);
            $context->run($relock, []);
            $context->lock(LockLevel::Registration);
            $context->run($relock, []);
        });

        $this->expectException(SystemFailure::class);
        $this->inRun(function (array $input, Context $context) use ($repository, $second, $lower): void {
            $repository->findForUpdate($second, $context);
            $context->run($lower, []);
        });
    }

    public function testARetriedAttemptStartsWithNoHeldRowsOrKeys(): void
    {
        [$first, $second] = $this->seedMany(2);
        $repository = $this->itemsAt(LockLevel::Quota);

        $useCase = new AdminProbe(
            function (array $input, Context $context, int $call) use ($repository, $first, $second): void {
                if ($call === 1) {
                    $repository->findForUpdate($second, $context);
                    $error = new RetryableFailure();
                    throw $error;
                }
                $repository->findForUpdate($first, $context);
            }
        );
        $this->runner->run($useCase, [], new CliActor(null));

        self::assertSame(2, $useCase->calls);
    }
}
