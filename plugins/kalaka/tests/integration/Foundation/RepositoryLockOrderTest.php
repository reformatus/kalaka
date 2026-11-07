<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

require_once __DIR__ . '/RepositoryTestCase.php';

/** The lock-order guard hears about every repository write, and checks key order within a level. */
final class RepositoryLockOrderTest extends RepositoryTestCase
{
    private function itemsAt(LockLevel $level, string $table = 'kalaka_test_items'): TestItemRepository
    {
        return new TestItemRepository($table, $level);
    }

    public function testAnEntityInsertThenALowerLockIsASystemFailureAndLeavesNothing(): void
    {
        $registrationLevel = $this->itemsAt(LockLevel::Registration);

        try {
            $this->inRun(function (Context $context) use ($registrationLevel): void {
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
            $this->inRun(function (Context $context) use ($logs): void {
                $logs->insert(new TestLog('new'), $context);
                $context->lock(LockLevel::Event);
            });
            self::fail('The run should have failed.');
        } catch (SystemFailure) {
            self::assertSame(0, $this->countRows('kalaka_test_logs'));
        }
    }

    public function testAnUpdateOfAnUnheldRowDeclaresItsLevel(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $repository = $this->itemsAt(LockLevel::Registration);

        try {
            $this->inRun(function (Context $context) use ($repository, $id): void {
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
            $this->inRun(function (Context $context) use ($repository, $id): void {
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

    public function testARowAlreadyHeldCanBeWrittenAfterAHigherLevel(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $event = $this->itemsAt(LockLevel::Event);
        $registration = $this->itemsAt(LockLevel::Registration, 'kalaka_test_items_other');

        $this->inRun(function (Context $context) use ($event, $registration, $id): void {
            $held = $event->findForUpdate($id, $context);
            self::assertInstanceOf(TestItem::class, $held);
            $registration->upsert(new TestItem('new'), $context);
            $held->label = 'B';
            $event->upsert($held, $context);
        });

        self::assertSame('B', $this->rowOf('kalaka_test_items', $id)['label'] ?? null);
    }

    public function testTwoRowsOfAKeyOrderedLevelInDescendingOrderAreASystemFailure(): void
    {
        $first = $this->seed('kalaka_test_items', 'A');
        $second = $this->seed('kalaka_test_items', 'B');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->expectException(SystemFailure::class);
        $this->inRun(function (Context $context) use ($repository, $first, $second): void {
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($first, $context);
        });
    }

    public function testAscendingOrderAndTheSameRowTwicePass(): void
    {
        $first = $this->seed('kalaka_test_items', 'A');
        $second = $this->seed('kalaka_test_items', 'B');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (Context $context) use ($repository, $first, $second): void {
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

        $this->expectException(SystemFailure::class);
        $this->inRun(function (Context $context) use ($items, $other, $a, $b): void {
            $other->findForUpdate($b, $context);
            $items->findForUpdate($a, $context);
        });
    }

    public function testAtLevelOtherAnyKeyOrderPasses(): void
    {
        $first = $this->seed('kalaka_test_items', 'A');
        $second = $this->seed('kalaka_test_items', 'B');
        $repository = $this->itemsAt(LockLevel::Other);

        $this->inRun(function (Context $context) use ($repository, $first, $second): void {
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($first, $context);
        });

        self::addToAssertionCount(1);
    }

    public function testAnInsertAtTheLevelOfAHeldRowPasses(): void
    {
        $id = $this->seed('kalaka_test_items', 'A');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (Context $context) use ($repository, $id): void {
            $repository->findForUpdate($id, $context);
            // A new row is checked for level only (D5).
            $repository->upsert(new TestItem('new'), $context);
        });

        self::assertSame(2, $this->countRows('kalaka_test_items'));
    }

    public function testAFindWhereForUpdateRecordsEveryReturnedRowAsHeld(): void
    {
        $first = $this->seed('kalaka_test_items', 'same');
        $second = $this->seed('kalaka_test_items', 'same');
        $repository = $this->itemsAt(LockLevel::Quota);

        $this->inRun(function (Context $context) use ($repository, $first, $second): void {
            $repository->byLabelForUpdate('same', $context);
            // Held already, so neither the order nor a later level matters.
            $repository->findForUpdate($second, $context);
            $repository->findForUpdate($first, $context);
        });

        self::addToAssertionCount(1);
    }

    public function testANestedRunSharesTheHeldRowsAndKeysOfTheOuterRun(): void
    {
        $first = $this->seed('kalaka_test_items', 'A');
        $second = $this->seed('kalaka_test_items', 'B');
        $repository = $this->itemsAt(LockLevel::Quota);
        $nested = new AdminProbe(function (array $input, Context $context) use ($repository, $first): void {
            $repository->findForUpdate($first, $context);
        });

        $this->expectException(SystemFailure::class);
        $this->inRun(function (Context $context) use ($repository, $second, $nested): void {
            $repository->findForUpdate($second, $context);
            $context->run($nested, []);
        });
    }

    public function testARetriedAttemptStartsWithNoHeldRowsOrKeys(): void
    {
        $first = $this->seed('kalaka_test_items', 'A');
        $second = $this->seed('kalaka_test_items', 'B');
        $repository = $this->itemsAt(LockLevel::Quota);

        $useCase = new AdminProbe(function (array $input, Context $context, int $call) use ($repository, $first, $second): void {
            if ($call === 1) {
                $repository->findForUpdate($second, $context);
                throw new RetryableFailure();
            }
            $repository->findForUpdate($first, $context);
        });
        $this->runner->run($useCase, [], new CliActor(null));

        self::assertSame(2, $useCase->calls);
    }
}
