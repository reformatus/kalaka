<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Grant\SaveGrant;
use Kalaka\Admin\View\CopyViews;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveMasterCounts;
use Kalaka\Admin\View\SaveView;
use Kalaka\Admin\View\View;
use Kalaka\Admin\View\ViewList;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * S37: the counted members of the sum row, stored with a view (`counts`, validated against the field's
 * options) and, for the master view, in the event's master row of `kalaka_views`: at most one per event,
 * never listed as a view, never a grant's view, copied with the views.
 */
final class ViewCountsTest extends GrantTestCase
{
    private const OPTIONS = ['lunch' => ['veg', 'normal'], 'module' => ['a', 'b']];

    /**
     * @param array<string, mixed> $counts
     * @return array<string, mixed>
     */
    private function viewInput(int $event, array $counts): array
    {
        return [
            'event_id' => $event,
            'name' => 'Ebédlista',
            'filter' => [],
            'columns' => [
                ['column' => 'code', 'write' => false],
                ['column' => 'status', 'write' => false],
                ['column' => 'field:lunch', 'write' => false],
            ],
            'counts' => $counts,
        ];
    }

    private function saveCounts(int $event, string $column, array $members, ?UserActor $actor = null): void
    {
        $this->exec(
            new SaveMasterCounts(options: self::OPTIONS),
            ['event_id' => $event, 'column' => $column, 'members' => $members],
            $actor
        );
    }

    /** @return list<array<string, ?string>> */
    private function masterRows(int $event): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}kalaka_views WHERE event_id = %d AND master = 1",
            $event
        ), ARRAY_A);

        return is_array($rows) ? array_values($rows) : [];
    }

    public function testAViewStoresItsCountsInColumnOrder(): void
    {
        $event = $this->createEvent();
        $id = $this->exec(
            new SaveView(options: self::OPTIONS),
            $this->viewInput($event, ['field:lunch' => ['veg'], 'status' => ['registered', 'waitlisted']])
        );

        self::assertSame(
            '{"status":["registered","waitlisted"],"field:lunch":["veg"]}',
            $this->viewRow($id)['counts']
        );
    }

    public function testASaveWithoutCountsKeepsTheStoredOnesForTheColumnsStillListedAndEmptyCountsClearThem(): void
    {
        $event = $this->createEvent();
        $input = $this->viewInput($event, ['field:lunch' => ['veg'], 'status' => ['waitlisted']]);
        $id = $this->exec(new SaveView(options: self::OPTIONS), $input);
        self::assertIsInt($id);

        // Renamed, without a `counts` key: the counts stay.
        $renamed = ['id' => $id, 'version' => 1, 'name' => 'Átnevezve'] + $input;
        unset($renamed['counts']);
        $this->exec(new SaveView(), $renamed);
        self::assertSame('{"status":["waitlisted"],"field:lunch":["veg"]}', $this->viewRow($id)['counts']);

        // Without `counts` and without the Ebéd column: only the status counts stay.
        $narrowed = ['version' => 2, 'columns' => array_slice($input['columns'], 0, 2)] + $renamed;
        $this->exec(new SaveView(), $narrowed);
        self::assertSame('{"status":["waitlisted"]}', $this->viewRow($id)['counts']);

        // An empty `counts` replaces them: every column counts its default again.
        $this->exec(new SaveView(), ['version' => 3, 'counts' => []] + $narrowed);
        self::assertSame('{}', $this->viewRow($id)['counts']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badCounts(): iterable
    {
        yield 'a member that is not an option' => [['field:lunch' => ['fish']]];
        yield 'a status that does not exist' => [['status' => ['paid']]];
        yield 'a column the view does not list' => [['field:module' => ['a']]];
        yield 'a column that cannot count' => [['code' => ['x']]];
        yield 'a member twice' => [['field:lunch' => ['veg', 'veg']]];
        yield 'a list, not an object' => [[['veg']]];
    }

    /**
     * @dataProvider badCounts
     * @param array<string, mixed> $counts
     */
    public function testCountsThatDoNotFitTheViewAreRefused(array $counts): void
    {
        $event = $this->createEvent();

        try {
            $this->exec(new SaveView(options: self::OPTIONS), $this->viewInput($event, $counts));
            self::fail('Expected InvalidInput.');
        } catch (InvalidInput) {
            self::assertSame(0, $this->viewCount());
        }
    }

    public function testTheFirstMasterCountsInsertTheMasterRowAndLaterOnesUpdateIt(): void
    {
        $event = $this->createEvent();

        $this->saveCounts($event, 'field:lunch', ['veg']);
        $this->saveCounts($event, 'status', ['registered']);
        $this->saveCounts($event, 'field:lunch', []);

        $rows = $this->masterRows($event);
        self::assertCount(1, $rows);
        self::assertSame(['', '[]', '[]', '{"status":["registered"]}', '1', (string) $event, '3'], [
            $rows[0]['name'], $rows[0]['filter'], $rows[0]['columns'], $rows[0]['counts'], $rows[0]['master'],
            $rows[0]['master_event'], $rows[0]['version'],
        ]);
        self::assertSame(['status' => ['registered']], (new ViewList())->masterCounts($event));
    }

    public function testSavingMasterCountsIsAuditedOnTheMasterRow(): void
    {
        $event = $this->createEvent();
        $this->saveCounts($event, 'field:lunch', ['veg']);
        $id = (int) $this->masterRows($event)[0]['id'];

        $entries = $this->entries();
        self::assertSame(SaveMasterCounts::name(), end($entries)['action']);
        self::assertSame(['view/' . $event . '/' . $id], $this->subjectsOf(end($entries)['id']));
    }

    public function testUnchangedMasterCountsWriteNothing(): void
    {
        $event = $this->createEvent();
        $this->saveCounts($event, 'field:lunch', ['veg']);
        $this->saveCounts($event, 'field:lunch', ['veg']);
        $this->saveCounts($event, 'status', []);

        self::assertSame('1', $this->masterRows($event)[0]['version']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function badMasterCounts(): iterable
    {
        yield 'not an option' => ['field:lunch', ['fish']];
        yield 'not a status' => ['status', ['paid']];
        yield 'a field without options' => ['field:note', ['x']];
        yield 'a column that cannot count' => ['code', ['x']];
    }

    /**
     * @dataProvider badMasterCounts
     * @param list<string> $members
     */
    public function testMasterCountsThatDoNotFitAreRefused(string $column, array $members): void
    {
        $event = $this->createEvent();

        $this->expectException(InvalidInput::class);
        $this->saveCounts($event, $column, $members);
    }

    public function testOnlyFullAccessSavesMasterCounts(): void
    {
        $event = $this->createEvent();
        $holder = self::factory()->user->create();
        $this->exec(new SaveGrant(), ['event_id' => $event, 'user_id' => $holder, 'actions' => [self::EDIT]]);
        Access::forget();

        $this->expectException(PermissionDenied::class);
        $this->saveCounts($event, 'status', ['registered'], new UserActor($holder));
    }

    public function testTheDatabaseHoldsAtMostOneMasterRowPerEvent(): void
    {
        global $wpdb;
        $event = $this->createEvent();
        $this->saveCounts($event, 'status', ['registered']);

        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($wpdb->prefix . 'kalaka_views', [
            'event_id' => $event, 'version' => 1, 'name' => '', 'filter' => '[]', 'columns' => '[]',
            'counts' => '{}', 'master' => 1, 'master_event' => $event,
        ]);
        $wpdb->suppress_errors($suppress);

        self::assertFalse($inserted);
        self::assertCount(1, $this->masterRows($event));
        // Ordinary views are not limited.
        $this->plainView($event);
        $this->plainView($event);
        self::assertCount(2, (new ViewList())->ofEvent($event));
    }

    public function testTheMasterRowIsNeverListedEditedOrDeletedAsAView(): void
    {
        $event = $this->createEvent();
        $view = $this->plainView($event);
        $this->saveCounts($event, 'status', ['registered']);
        $master = (int) $this->masterRows($event)[0]['id'];

        self::assertSame([$view], array_column((new ViewList())->ofEvent($event), 'id'));

        $calls = [
            fn () => $this->exec(new SaveView(), [
                'id' => $master, 'version' => 1, 'event_id' => $event, 'name' => 'X', 'filter' => [],
                'columns' => [['column' => 'code', 'write' => false]],
            ]),
            fn () => $this->exec(new DeleteView(), ['id' => $master, 'version' => 1]),
            fn () => (new Access())->fullView($this->admin, $event, $master),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected NotFound.');
            } catch (NotFound) {
                self::assertCount(1, $this->masterRows($event));
            }
        }
    }

    public function testAGrantNeverPointsAtTheMasterRow(): void
    {
        $event = $this->createEvent();
        $this->saveCounts($event, 'status', ['registered']);
        $master = (int) $this->masterRows($event)[0]['id'];

        $this->expectException(InvalidInput::class);
        $this->exec(new SaveGrant(), [
            'event_id' => $event,
            'user_id' => self::factory()->user->create(),
            'view_id' => $master,
            'actions' => [],
        ]);
    }

    public function testAGrantRowPointingAtTheMasterRowHoldsNothing(): void
    {
        global $wpdb;
        $event = $this->createEvent();
        $this->saveCounts($event, 'status', ['registered']);
        $master = (int) $this->masterRows($event)[0]['id'];
        $holder = self::factory()->user->create();
        $wpdb->insert($wpdb->prefix . 'kalaka_grants', [
            'event_id' => $event, 'version' => 1, 'user_id' => $holder, 'view_id' => $master, 'actions' => '[]',
        ]);

        self::assertSame([], (new Access())->heldViews(new UserActor($holder), $event));
    }

    public function testTheMasterViewAndAMasterGrantCarryTheMasterCounts(): void
    {
        $event = $this->createEvent();
        $holder = self::factory()->user->create();
        $this->exec(new SaveGrant(), ['event_id' => $event, 'user_id' => $holder, 'actions' => [self::EDIT]]);
        $this->saveCounts($event, 'field:lunch', ['veg']);

        self::assertSame(['field:lunch' => ['veg']], (new Access())->master($this->admin, $event)->counts());
        $held = (new Access())->heldViews(new UserActor($holder), $event);
        self::assertCount(1, $held);
        self::assertTrue($held[0]->isMaster());
        self::assertSame(['field:lunch' => ['veg']], $held[0]->counts());
    }

    public function testCopyViewsCarriesTheMasterRow(): void
    {
        $from = $this->createEvent();
        $to = $this->createEvent('Jövő évi tábor');
        $this->plainView($from);
        $this->saveCounts($from, 'field:lunch', ['veg', 'normal']);

        $this->exec(new CopyViews(), ['from_event_id' => $from, 'to_event_id' => $to]);

        self::assertCount(1, (new ViewList())->ofEvent($to));
        self::assertSame(['field:lunch' => ['veg', 'normal']], (new ViewList())->masterCounts($to));
        self::assertCount(1, $this->masterRows($to));
        self::assertCount(1, $this->masterRows($from));
    }

    public function testCopyViewsReplacesTheTargetsMasterCounts(): void
    {
        $from = $this->createEvent();
        $to = $this->createEvent('Jövő évi tábor');
        $this->saveCounts($from, 'status', ['waitlisted']);
        $this->saveCounts($to, 'field:lunch', ['veg']);

        $this->exec(new CopyViews(), ['from_event_id' => $from, 'to_event_id' => $to]);

        self::assertCount(1, $this->masterRows($to));
        self::assertSame(['status' => ['waitlisted']], (new ViewList())->masterCounts($to));
    }

    public function testTheMasterShapeKeepsStatusFirstAndChecksColumns(): void
    {
        self::assertSame(
            ['status' => ['registered'], 'field:a' => ['x'], 'field:b' => ['y']],
            ViewShape::forMaster(['field:b' => ['y'], 'status' => ['registered'], 'field:a' => ['x']])->counts
        );
        self::assertTrue(View::newMaster(1, [])->master);

        // A decision column's states are counted like a field's; a column that has no members is refused.
        self::assertSame(
            ['decision:confirmation' => ['confirmed']],
            ViewShape::forMaster(['decision:confirmation' => ['confirmed']])->counts
        );
        $this->expectException(InvalidInput::class);
        ViewShape::forMaster(['code' => ['x']]);
    }
}
