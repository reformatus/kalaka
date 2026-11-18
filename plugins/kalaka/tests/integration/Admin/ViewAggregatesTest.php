<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\AggregateKind;
use Kalaka\Admin\Api\AggregateSpec;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Error\InvalidInput;

/** S37: the sum row's aggregates over the whole filtered view, every kind, and a grant holder's scope. */
final class ViewAggregatesTest extends AdminTestCase
{
    private const EVENT = 7;

    private const COLUMNS = [
        'code', 'status', 'field:lunch', 'field:programs', 'field:photo', 'field:nights', 'field:rating',
        'field:note', 'field:team', 'decision:confirmation',
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $rows = [
            // code, status, lunch, programs, photo, nights, rating, note, team, confirmation
            ['00A0', 'registered', 'veg', '["hike","craft"]', '1', '3', '4', 'Késő', 'kék', 'confirmed'],
            ['00A2', 'registered', 'VEG', '["sport"]', '1', '2.5', '5', '', 'kék', 'pending'],
            ['00A5', 'registered', 'normal', '["craft"]', null, '', '3', null, 'piros', 'confirmed'],
            ['00A8', 'waitlisted', 'normal', null, '1', '4', null, 'Allergia', 'piros', null],
            ['00B0', 'withdrawn', 'veg', '["hike"]', '0', '10', '1', 'x', 'kék', 'confirmed'],
        ];
        foreach ($rows as [$code, $status, $lunch, $programs, $photo, $nights, $rating, $note, $team, $confirmation]) {
            $this->addRegistration(self::EVENT, $code, $status);
            foreach (
                [
                    'lunch' => $lunch, 'programs' => $programs, 'photo' => $photo, 'nights' => $nights,
                    'rating' => $rating, 'note' => $note, 'team' => $team,
                ] as $key => $value
            ) {
                if ($value !== null) {
                    $this->addValue(self::EVENT, $code, $key, $value);
                }
            }
            if ($confirmation !== null) {
                $this->addDecision(self::EVENT, $code, 'confirmation', 'pending');
                $this->addDecision(self::EVENT, $code, 'confirmation', $confirmation);
            }
        }
        // Another event's rows never count.
        $this->addRegistration(self::EVENT + 1, '00A0');
        $this->addValue(self::EVENT + 1, '00A0', 'lunch', 'veg');
        $this->addValue(self::EVENT + 1, '00A0', 'nights', '100');
    }

    /** @param list<array<string, mixed>> $filter */
    private function view(array $filter = [], ?string $bound = null): HeldView
    {
        $columns = array_map(static fn (string $c): array => ['column' => $c, 'write' => false], self::COLUMNS);

        return new HeldView(self::EVENT, 1, 1, 'Nézet', ViewShape::of($filter, $columns), $bound);
    }

    /** @return list<AggregateSpec> */
    private static function specs(): array
    {
        return [
            new AggregateSpec('code', AggregateKind::Rows),
            new AggregateSpec('status', AggregateKind::Members, ['registered', 'waitlisted']),
            new AggregateSpec('field:lunch', AggregateKind::Members, ['veg']),
            new AggregateSpec('field:programs', AggregateKind::Members, ['hike', 'sport']),
            new AggregateSpec('decision:confirmation', AggregateKind::Members, ['confirmed']),
            new AggregateSpec('field:photo', AggregateKind::Yes),
            new AggregateSpec('field:nights', AggregateKind::Sum),
            new AggregateSpec('field:note', AggregateKind::Filled),
            new AggregateSpec('field:rating', AggregateKind::Average),
        ];
    }

    public function testEveryKindCoversTheWholeView(): void
    {
        self::assertSame(
            // „VEG” matches „veg” by the column's collation; the multiple choice counts a list holding any member.
            [5, 4, 3, 3, 3, 3, '19.5', 3, '3.25'],
            (new ViewQuery())->aggregates($this->view(), self::specs())
        );
    }

    /** A consent shown but not ticked stores '0': only exactly '1' counts, never a non-empty value. */
    public function testTheYesCountIsExactlyOneNeverNonEmpty(): void
    {
        $rows = (new ViewQuery())->aggregates($this->view(), [new AggregateSpec('field:photo', AggregateKind::Yes)]);

        // '1' on three registrations, '0' on one, no row on another.
        self::assertSame([3], $rows);
    }

    public function testTheFilterNarrowsEveryKind(): void
    {
        $view = $this->view([['column' => 'status', 'in' => ['registered']]]);

        self::assertSame([3, 3, 2, 2, 2, 2, '5.5', 1, '4'], (new ViewQuery())->aggregates($view, self::specs()));
    }

    /** S36-9: a grant holder's aggregates cover only their view, with their bound value. */
    public function testAGrantHoldersAggregatesCoverOnlyTheirBoundView(): void
    {
        $view = $this->view([['column' => 'field:team', 'parameter' => true]], 'piros');

        self::assertSame([2, 2, 0, 0, 1, 1, '4', 1, '3'], (new ViewQuery())->aggregates($view, self::specs()));
    }

    public function testSumAndAverageAreNullOverNoValue(): void
    {
        $view = $this->view([['column' => 'field:team', 'in' => ['nincs ilyen']]]);

        self::assertSame(
            [0, null, null],
            (new ViewQuery())->aggregates($view, [
                new AggregateSpec('code', AggregateKind::Rows),
                new AggregateSpec('field:nights', AggregateKind::Sum),
                new AggregateSpec('field:rating', AggregateKind::Average),
            ])
        );
    }

    public function testOneStatementForEverySpec(): void
    {
        global $wpdb;
        $before = $wpdb->num_queries;

        (new ViewQuery())->aggregates($this->view(), self::specs());

        self::assertSame(1, $wpdb->num_queries - $before);
    }

    public function testAColumnTheViewDoesNotListIsRefused(): void
    {
        $columns = [['column' => 'code', 'write' => false]];
        $view = new HeldView(self::EVENT, 1, 1, 'Nézet', ViewShape::of([], $columns), null);

        $this->expectException(InvalidInput::class);
        (new ViewQuery())->aggregates($view, [new AggregateSpec('field:nights', AggregateKind::Sum)]);
    }

    /** @return iterable<string, array{string, AggregateKind, list<string>}> */
    public static function misfits(): iterable
    {
        yield 'members without members' => ['status', AggregateKind::Members, []];
        yield 'members on the code' => ['code', AggregateKind::Members, ['x']];
        yield 'a sum of the status' => ['status', AggregateKind::Sum, []];
        yield 'a yes count with members' => ['field:photo', AggregateKind::Yes, ['1']];
    }

    /**
     * @dataProvider misfits
     * @param list<string> $members
     */
    public function testASpecThatDoesNotFitItsColumnIsRefused(string $column, AggregateKind $kind, array $members): void
    {
        $this->expectException(InvalidInput::class);
        new AggregateSpec($column, $kind, $members);
    }
}
