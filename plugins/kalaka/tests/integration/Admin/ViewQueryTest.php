<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\Api\ViewRow;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Registrations\Api\RegistrationKey;

/** AC5 to AC8: the master view, the rows of a view and the containment check. */
final class ViewQueryTest extends AdminTestCase
{
    private const EVENT = 7;

    private ViewQuery $query;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->query = new ViewQuery();
    }

    /** Five registrations in all four statuses, written out of order; the codes 00A8 and 00B0 share a time. */
    private function seedStatuses(): void
    {
        $this->addRegistration(self::EVENT, '00B0', 'removed', '2026-11-30 08:03:00');
        $this->addRegistration(self::EVENT, '00A5', 'registered', '2026-11-30 08:02:00');
        $this->addRegistration(self::EVENT, '00A0', 'registered', '2026-11-30 08:00:00');
        $this->addRegistration(self::EVENT, '00A8', 'withdrawn', '2026-11-30 08:03:00');
        $this->addRegistration(self::EVENT, '00A2', 'waitlisted', '2026-11-30 08:01:00');
        // Another event's data never shows.
        $this->addRegistration(self::EVENT + 1, '00A0');
        $this->addValue(self::EVENT + 1, '00A0', 'c_key', 'elsewhere');
        $this->addDecision(self::EVENT + 1, '00A0', 'payment_hold', 'held');
    }

    /** @param list<array{column: string, write: bool}> $columns @return list<string> */
    private function columnIds(array $columns): array
    {
        return array_column($columns, 'column');
    }

    /** A held view of the event: the given shape (null: the master view) with the bound value. */
    private function held(?ViewShape $shape, ?string $bound = null): HeldView
    {
        return new HeldView(self::EVENT, $shape === null ? null : 1, null, null, $shape, $bound);
    }

    /** @return list<array{key: RegistrationKey, values: array<string, string|int|null>}> every row, in order */
    private function viewRows(HeldView $view): array
    {
        return array_map(
            static fn (ViewRow $row): array => ['key' => $row->key, 'values' => $row->values],
            $this->query->page($view, limit: ViewQuery::MAX_LIMIT)->rows
        );
    }

    /**
     * @param list<array{key: RegistrationKey, values: array<string, string|int|null>}> $rows
     * @return list<string>
     */
    private function codes(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['key']->code, $rows);
    }

    /** @param list<array<string, mixed>> $filter @param list<array<string, mixed>> $columns */
    private function shape(array $filter, array $columns): ViewShape
    {
        return ViewShape::of($filter, $columns);
    }

    public function testTheMasterViewListsEveryRegistrationAndColumnInOrder(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'b_key', 'x');
        $this->addValue(self::EVENT, '00A0', 'a_key', 'y');
        $this->addValue(self::EVENT, '00A5', 'a_key', 'z');
        $this->addDecision(self::EVENT, '00A0', 'confirmation', 'pending');
        $this->addDecision(self::EVENT, '00A0', 'confirmation', 'confirmed');

        $master = $this->query->columns($this->held(null));

        self::assertSame(
            ['code', 'status', 'serial', 'field:a_key', 'field:b_key', 'decision:confirmation'],
            $this->columnIds($master)
        );
        self::assertSame([false, false, false, true, true, false], array_column($master, 'write'));

        $rows = $this->viewRows($this->held(null));

        self::assertSame(['00A0', '00A2', '00A5', '00A8', '00B0'], $this->codes($rows));
        self::assertSame(
            [
                'code' => '00A0', 'status' => 'registered', 'serial' => 1,
                'field:a_key' => 'y', 'field:b_key' => 'x', 'decision:confirmation' => 'confirmed',
            ],
            $rows[0]['values']
        );
        self::assertSame(
            [
                'code' => '00A2', 'status' => 'waitlisted', 'serial' => 1,
                'field:a_key' => null, 'field:b_key' => null, 'decision:confirmation' => null,
            ],
            $rows[1]['values']
        );
        self::assertSame(
            [
                'code' => '00A5', 'status' => 'registered', 'serial' => 2,
                'field:a_key' => 'z', 'field:b_key' => null, 'decision:confirmation' => null,
            ],
            $rows[2]['values']
        );
        self::assertSame(['withdrawn', null], [$rows[3]['values']['status'], $rows[3]['values']['serial']]);
        self::assertSame(['removed', null], [$rows[4]['values']['status'], $rows[4]['values']['serial']]);
        foreach ($rows as $row) {
            self::assertSame(self::EVENT, $row['key']->eventId);
        }
    }

    public function testTheMasterViewOfAnEventWithNoDataHasTheThreeBuiltInColumns(): void
    {
        self::assertSame(['code', 'status', 'serial'], $this->columnIds($this->query->columns($this->held(null))));
        self::assertSame([], $this->viewRows($this->held(null)));
        self::assertSame(0, $this->query->count($this->held(null)));
    }

    public function testAStatusTermMatchesTheStatusesInTheList(): void
    {
        $this->seedStatuses();
        $shape = $this->shape(
            [['column' => 'status', 'in' => ['waitlisted', 'removed']]],
            [['column' => 'code', 'write' => false]]
        );

        self::assertSame(['00A2', '00B0'], $this->codes($this->viewRows($this->held($shape))));
    }

    public function testAFieldTermMatchesTheStoredValuesUnderTheColumnCollation(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'Alma');
        $this->addValue(self::EVENT, '00A2', 'module', 'körte');
        $this->addValue(self::EVENT, '00A5', 'module', '["a","b"]');
        $this->addValue(self::EVENT, '00A8', 'module', '100%');
        $this->addValue(self::EVENT, '00A0', 'other', 'körte');
        $columns = [['column' => 'code', 'write' => false]];

        $match = fn (array $values): array => $this->codes($this->viewRows($this->held(
            $this->shape([['column' => 'field:module', 'in' => $values]], $columns)
        )));

        self::assertSame(['00A0', '00A2'], $match(['alma', 'KÖRTE']));
        self::assertSame(['00A5'], $match(['["a","b"]']));
        self::assertSame(['00A8'], $match(['100%']));
        self::assertSame([], $match(['b']));
    }

    public function testADecisionTermMatchesTheLatestStateOnly(): void
    {
        $this->seedStatuses();
        $this->addDecision(self::EVENT, '00A0', 'confirmation', 'confirmed');
        $this->addDecision(self::EVENT, '00A0', 'confirmation', 'pending');
        $this->addDecision(self::EVENT, '00A2', 'confirmation', 'pending');
        $this->addDecision(self::EVENT, '00A2', 'confirmation', 'confirmed');
        $this->addDecision(self::EVENT, '00A5', 'payment_hold', 'confirmed');
        $columns = [['column' => 'code', 'write' => false], ['column' => 'decision:confirmation', 'write' => false]];

        $rows = $this->viewRows($this->held(
            $this->shape([['column' => 'decision:confirmation', 'in' => ['confirmed']]], $columns)
        ));

        self::assertSame(['00A2'], $this->codes($rows));
        self::assertSame(['code' => '00A2', 'decision:confirmation' => 'confirmed'], $rows[0]['values']);
    }

    public function testTwoTermsTogetherMatchOnlyRegistrationsMeetingBoth(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'a');
        $this->addValue(self::EVENT, '00A2', 'module', 'a');
        $this->addValue(self::EVENT, '00A5', 'module', 'b');
        $shape = $this->shape(
            [['column' => 'status', 'in' => ['registered']], ['column' => 'field:module', 'in' => ['a']]],
            [['column' => 'code', 'write' => false]]
        );

        self::assertSame(['00A0'], $this->codes($this->viewRows($this->held($shape))));
    }

    public function testAnEmptyFilterMatchesEveryRegistrationAndRowsCarryOnlyTheListedColumns(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'a');
        $shape = $this->shape([], [
            ['column' => 'status', 'write' => false],
            ['column' => 'field:module', 'write' => true],
        ]);

        $rows = $this->viewRows($this->held($shape));

        self::assertCount(5, $rows);
        self::assertSame(['status' => 'registered', 'field:module' => 'a'], $rows[0]['values']);
        self::assertSame(['status' => 'waitlisted', 'field:module' => null], $rows[1]['values']);
    }

    public function testTheSerialIsOnlyReadWhenItIsListed(): void
    {
        $this->seedStatuses();
        $without = $this->shape([], [['column' => 'code', 'write' => false]]);
        $with = $this->shape([], [['column' => 'serial', 'write' => false]]);

        // The count and the page, then the sorszám only when listed.
        self::assertCount(2, $this->selects(fn () => $this->viewRows($this->held($without))));
        self::assertCount(3, $this->selects(fn () => $this->viewRows($this->held($with))));
    }

    public function testTheParameterIsBoundToTheOneValue(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'a');
        $this->addValue(self::EVENT, '00A2', 'module', 'a');
        $this->addValue(self::EVENT, '00A5', 'module', 'b');
        $this->addValue(self::EVENT, '00A8', 'module', 'b');
        $shape = $this->shape(
            [['column' => 'field:module', 'parameter' => true]],
            [['column' => 'code', 'write' => false], ['column' => 'field:module', 'write' => false]]
        );

        $a = $this->viewRows($this->held($shape, 'a'));
        $b = $this->viewRows($this->held($shape, 'b'));

        self::assertSame(['00A0', '00A2'], $this->codes($a));
        self::assertSame(['code' => '00A0', 'field:module' => 'a'], $a[0]['values']);
        self::assertSame(['00A5', '00A8'], $this->codes($b));
        self::assertSame([], $this->codes($this->viewRows($this->held($shape, 'c'))));
    }

    /** A2, S36-4: a filter and a bound value that do not fit each other match nothing (fail closed). */
    public function testAParameterWithoutABoundValueOrABoundValueWithoutAParameterMatchesNothing(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'a');
        $code = [['column' => 'code', 'write' => false]];
        $parameter = $this->shape([['column' => 'field:module', 'parameter' => true]], $code);
        $fixed = $this->shape([['column' => 'field:module', 'in' => ['a']]], [['column' => 'code', 'write' => false]]);
        $key = [new RegistrationKey(self::EVENT, '00A0')];

        self::assertSame([], $this->viewRows($this->held($parameter)));
        self::assertSame(0, $this->query->count($this->held($parameter)));
        self::assertFalse($this->query->contains($this->held($parameter), $key));
        self::assertSame([], $this->viewRows($this->held($fixed, 'a')));
        self::assertFalse($this->query->contains($this->held($fixed, 'a'), $key));
        self::assertSame([], $this->viewRows($this->held(null, 'a')));
        self::assertSame(['00A0'], $this->codes($this->viewRows($this->held($fixed))));
    }

    /** S36-6: the default order, a sort by a listed column, paging with a total. */
    public function testAPageIsSortedByAListedColumnAndCarriesTheTotal(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'name', 'Cecil');
        $this->addValue(self::EVENT, '00A2', 'name', 'Ábel');
        $this->addValue(self::EVENT, '00A5', 'name', 'Béla');
        $this->addDecision(self::EVENT, '00A5', 'confirmation', 'b');
        $this->addDecision(self::EVENT, '00A0', 'confirmation', 'a');
        $view = $this->held($this->shape([], [
            ['column' => 'code', 'write' => false],
            ['column' => 'status', 'write' => false],
            ['column' => 'field:name', 'write' => false],
            ['column' => 'decision:confirmation', 'write' => false],
        ]));
        $codes = fn (?string $sort, bool $descending = false, int $limit = 50, int $offset = 0): array => array_map(
            static fn (ViewRow $row): string => $row->key->code,
            $this->query->page($view, $sort, $descending, $limit, $offset)->rows
        );

        self::assertSame(['00A0', '00A2', '00A5', '00A8', '00B0'], $codes(null));
        self::assertSame(['00B0', '00A8', '00A5', '00A2', '00A0'], $codes(null, true));
        // No value sorts first; then the Hungarian collation: Ábel, Béla, Cecil.
        self::assertSame(['00A8', '00B0', '00A2', '00A5', '00A0'], $codes('field:name'));
        self::assertSame(['00A2', '00A8', '00B0', '00A0', '00A5'], $codes('decision:confirmation'));
        self::assertSame(['00A0', '00A5', '00B0', '00A2', '00A8'], $codes('status'));
        self::assertSame(['00A5', '00A8'], $codes(null, false, 2, 2));

        $page = $this->query->page($view, null, false, 2, 4);
        self::assertSame(5, $page->total);
        self::assertCount(1, $page->rows);
        self::assertSame(['code', 'status', 'field:name', 'decision:confirmation'], $this->columnIds($page->columns));
    }

    /** @return iterable<string, array{?string, int, int}> */
    public static function refusedPages(): iterable
    {
        yield 'an unlisted column' => ['field:other', 50, 0];
        yield 'the code' => ['code', 50, 0];
        yield 'an unknown column' => ['secret', 50, 0];
        yield 'no rows' => [null, 0, 0];
        yield 'too many rows' => [null, ViewQuery::MAX_LIMIT + 1, 0];
        yield 'a negative offset' => [null, 50, -1];
    }

    /** @dataProvider refusedPages */
    public function testASortOutsideTheViewOrAPageOutOfRangeIsInvalidInput(?string $sort, int $limit, int $offset): void
    {
        $view = $this->held($this->shape([], [['column' => 'code', 'write' => false]]));

        $this->expectException(InvalidInput::class);
        $this->query->page($view, $sort, false, $limit, $offset);
    }

    public function testNoStatementSelectsTheSecretOrLeavesTheEvent(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'a');
        $this->addDecision(self::EVENT, '00A0', 'confirmation', 'confirmed');
        $shape = $this->shape(
            [
                ['column' => 'status', 'in' => ['registered']],
                ['column' => 'field:module', 'in' => ['a']],
                ['column' => 'decision:confirmation', 'in' => ['confirmed']],
            ],
            [
                ['column' => 'code', 'write' => false],
                ['column' => 'serial', 'write' => false],
                ['column' => 'field:module', 'write' => false],
                ['column' => 'decision:confirmation', 'write' => false],
            ]
        );

        $statements = $this->selects(function () use ($shape): void {
            $this->query->columns($this->held(null));
            $this->viewRows($this->held($shape));
            $this->query->contains($this->held($shape), [new RegistrationKey(self::EVENT, '00A0')]);
        });

        // The master columns (2), the count, the page, its values, its decisions and the sorszám (5), contains (1).
        self::assertCount(2 + 5 + 1, $statements);
        foreach ($statements as $sql) {
            self::assertStringNotContainsString('secret', $sql);
            self::assertMatchesRegularExpression('/\bevent_id = ' . self::EVENT . '\b/', $sql);
        }
    }

    public function testContainsIsTrueWhenEveryRegistrationIsInsideTheFilter(): void
    {
        $this->seedStatuses();
        $this->addValue(self::EVENT, '00A0', 'module', 'a');
        $this->addValue(self::EVENT, '00A2', 'module', 'a');
        $this->addValue(self::EVENT, '00A5', 'module', 'b');
        $shape = $this->shape(
            [['column' => 'field:module', 'parameter' => true]],
            [['column' => 'code', 'write' => false]]
        );
        $key = static fn (string $code, int $event = self::EVENT): RegistrationKey
            => new RegistrationKey($event, $code);

        self::assertTrue($this->query->contains($this->held($shape, 'a'), [$key('00A0'), $key('00A2')]));
        self::assertTrue($this->query->contains($this->held($shape, 'a'), [$key('00A0'), $key('00A0')]));
        self::assertFalse($this->query->contains($this->held($shape, 'a'), [$key('00A0'), $key('00A5')]));
        self::assertFalse($this->query->contains($this->held($shape, 'a'), [$key('00A0'), $key('00B3')]));
        self::assertFalse($this->query->contains($this->held($shape, 'a'), [$key('00A8')]));
        self::assertFalse($this->query->contains($this->held($shape, 'a'), [$key('00A0', self::EVENT + 1)]));
        self::assertTrue($this->query->contains($this->held($shape, 'a'), []));
        self::assertTrue($this->query->contains($this->held($shape, 'b'), [$key('00A5')]));
    }

    public function testContainsWithAnEmptyFilterIsTrueForEveryRegistrationOfTheEvent(): void
    {
        $this->seedStatuses();
        $shape = $this->shape([], [['column' => 'code', 'write' => false]]);

        self::assertTrue($this->query->contains(
            $this->held($shape),
            [new RegistrationKey(self::EVENT, '00A0'), new RegistrationKey(self::EVENT, '00B0')]
        ));
    }

    /** @return list<string> the SELECTs the database receives while the work runs */
    private function selects(\Closure $work): array
    {
        return array_values(array_filter(
            $this->statementsDuring($work),
            static fn (string $sql): bool => preg_match('/^\s*SELECT\b/i', $sql) === 1
        ));
    }

    /** @return list<string> */
    private function statementsDuring(\Closure $work): array
    {
        $seen = [];
        $capture = static function (string $query) use (&$seen): string {
            $seen[] = $query;

            return $query;
        };
        add_filter('query', $capture);
        try {
            $work();
        } finally {
            remove_filter('query', $capture);
        }

        return $seen;
    }
}
