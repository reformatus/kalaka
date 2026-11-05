<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

require_once __DIR__ . '/RepositoryTestCase.php';

/** `EntityRepository::delete()`: a delete by key and version that the audit and the evaluation see. */
final class RepositoryDeleteTest extends RepositoryTestCase
{
    private function seedItem(): TestItem
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'kalaka_test_items', [
            'label' => 'doomed',
            'note' => 'a note',
            'token' => 'a-secret',
            'reg_event' => 12,
            'reg_code' => '00A0',
            'version' => 1,
        ]);
        $item = $this->items->find($wpdb->insert_id);
        self::assertInstanceOf(TestItem::class, $item);

        return $item;
    }

    public function testADeleteRemovesTheRowRecordsTheColumnsAndMarksTheRegistration(): void
    {
        $item = $this->seedItem();
        $touched = null;

        $this->inRun(function (array $input, Context $context) use ($item, &$touched): void {
            $this->items->delete($item, $context);
            $touched = $context->touchedRegistrations();
        });

        self::assertNull($this->rowOf('kalaka_test_items', $item->id));
        self::assertEquals([new RegistrationKey(12, '00A0')], $touched);

        $entry = $this->onlyEntry();
        $changes = $this->decode($entry['changes']);
        self::assertSame(
            [
                ['test_item', null, (string) $item->id, 'label', 'doomed', null],
                ['test_item', null, (string) $item->id, 'note', 'a note', null],
                ['test_item', null, (string) $item->id, 'token', null, null],
                ['test_item', null, (string) $item->id, 'reg_event', '12', null],
                ['test_item', null, (string) $item->id, 'reg_code', '00A0', null],
            ],
            array_map(
                static fn (array $change): array => [
                    $change['type'],
                    $change['event'],
                    $change['key'],
                    $change['field'],
                    $change['before'] ?? null,
                    $change['after'] ?? null,
                ],
                $changes
            )
        );
        self::assertTrue($changes[2]['masked']);
        self::assertStringNotContainsString('a-secret', (string) $entry['changes']);

        $statements = $this->statementsDuring(fn () => self::assertNull($this->items->find($item->id)));
        self::assertNotSame([], $statements, 'The cached row was not dropped.');
    }

    public function testADeleteWithAStaleVersionConflictsAndKeepsTheRow(): void
    {
        global $wpdb;
        $item = $this->seedItem();
        $wpdb->update($wpdb->prefix . 'kalaka_test_items', ['version' => 2], ['id' => $item->id]);

        try {
            $this->inRun(fn (array $input, Context $context) => $this->items->delete($item, $context));
            self::fail('The delete should have conflicted.');
        } catch (VersionConflict) {
            self::assertNotNull($this->rowOf('kalaka_test_items', $item->id));
        }
    }

    public function testADeleteThroughAnEndedContextIsRefused(): void
    {
        $item = $this->seedItem();
        $kept = null;
        $this->inRun(static function (array $input, Context $context) use (&$kept): void {
            $kept = $context;
        });
        self::assertInstanceOf(Context::class, $kept);

        try {
            $this->items->delete($item, $kept);
            self::fail('The delete should have been refused.');
        } catch (SystemFailure) {
            self::assertNotNull($this->rowOf('kalaka_test_items', $item->id));
        }
    }

    public function testAnEntityThatWasNotLoadedCannotBeDeleted(): void
    {
        $this->expectException(SystemFailure::class);
        $this->inRun(fn (array $input, Context $context) => $this->items->delete(new TestItem('new', id: 1, version: 1), $context));
    }
}
