<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Api\AggregateKind;
use Kalaka\Admin\Api\AggregateSpec;
use Kalaka\Admin\Api\ColumnFilter;
use Kalaka\Admin\Api\FilterKind;
use Kalaka\Admin\Api\Narrowing;
use Kalaka\Admin\Api\SearchWord;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewPage;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\PerEventCostTestCase;

/** AC9: every statement of the view query stays inside its event; S37: the sum row's aggregates too. */
final class ViewQueryCostTest extends PerEventCostTestCase
{
    private const CODES = ['00A0', '00A2', '00A5', '00A8', '00B0', '00B3'];

    private function seedEvent(int $event): void
    {
        // phpcs:disable WordPress.DB.PreparedSQL -- test setup; each row is prepared, the table names are the prefix and constants
        global $wpdb;

        $registrations = [];
        $values = [];
        $decisions = [];
        foreach (self::CODES as $index => $code) {
            $registrations[] = $wpdb->prepare(
                "(%d, %s, 1, %s, 'secret', '2026-11-30 08:00:00.000000', '2026-11-30 08:00:00.000000')",
                $event,
                $code,
                $index % 2 === 0 ? 'registered' : 'waitlisted'
            );
            foreach (['module', 'team'] as $key) {
                $values[] = $wpdb->prepare("(%d, %s, %s, 1, %s)", $event, $code, $key, $index % 3 === 0 ? 'a' : 'b');
            }
            foreach (['confirmation', 'payment_hold'] as $kind) {
                foreach (['pending', 'confirmed', 'pending'] as $state) {
                    $decisions[] = $wpdb->prepare(
                        "(%d, %s, %s, %s, '2026-11-30 08:00:00.000000', '2026-11-30 08:00:00.000000',"
                        . " 'test_record', %d, '1', 'system')",
                        $event,
                        $code,
                        $kind,
                        $state,
                        $event
                    );
                }
            }
        }
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_registrations"
            . ' (event_id, code, version, status, secret, created_at, registered_at) VALUES '
            . implode(', ', $registrations)
        );
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_field_values (event_id, code, field_key, version, value) VALUES "
            . implode(', ', $values)
        );
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}kalaka_decisions (event_id, code, kind, state, effective_at, recorded_at,"
            . ' cause_type, cause_event_id, cause_key, actor_type) VALUES ' . implode(', ', $decisions)
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    /** @return iterable<string, array{string}> */
    public static function filters(): iterable
    {
        foreach (['no filter', 'status', 'field', 'decision', 'parameter', 'all kinds together'] as $filter) {
            yield $filter => [$filter];
        }
    }

    private function shape(string $filter): ViewShape
    {
        $listed = [
            ['column' => 'code', 'write' => false],
            ['column' => 'serial', 'write' => false],
            ['column' => 'field:module', 'write' => true],
            ['column' => 'decision:confirmation', 'write' => false],
        ];

        return ViewShape::of(match ($filter) {
            'no filter' => [],
            'status' => [['column' => 'status', 'in' => ['registered']]],
            'field' => [['column' => 'field:module', 'in' => ['a']]],
            'decision' => [['column' => 'decision:confirmation', 'in' => ['pending']]],
            'parameter' => [['column' => 'field:team', 'parameter' => true]],
            default => [
                ['column' => 'status', 'in' => ['registered', 'waitlisted']],
                ['column' => 'field:module', 'in' => ['a', 'b']],
                ['column' => 'decision:payment_hold', 'in' => ['pending']],
            ],
        }, $listed);
    }

    private static function held(int $event, ?ViewShape $shape): HeldView
    {
        $bound = $shape !== null && $shape->hasParameter() ? 'a' : null;

        return new HeldView($event, $shape === null ? null : 1, null, null, $shape, $bound);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function pages(): iterable
    {
        foreach (self::filters() as $name => [$filter]) {
            foreach ([null, 'status', 'field:module', 'decision:confirmation'] as $sort) {
                yield $name . ', sorted by ' . ($sort ?? 'the sorszám') => [$filter, $sort];
            }
        }
    }

    /** @dataProvider pages */
    public function testAPageStaysInsideItsEvent(string $filter, ?string $sort): void
    {
        $shape = $this->shape($filter);
        if ($sort === 'status') {
            $shape = ViewShape::of(json_decode($shape->filterJson(), true), [
                ['column' => 'status', 'write' => false],
                ...$shape->columns,
            ]);
        }

        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): ViewPage => (new ViewQuery())->page(self::held($event, $shape), $sort, true, 3, 1)
        );
    }

    /** @dataProvider filters */
    public function testTheCountStaysInsideItsEvent(string $filter): void
    {
        $shape = $this->shape($filter);

        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): int => (new ViewQuery())->count(self::held($event, $shape))
        );
    }

    public function testTheMasterViewStaysInsideItsEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): ViewPage => (new ViewQuery())->page(self::held($event, null))
        );
    }

    /** @dataProvider filters */
    public function testContainsStaysInsideItsEvent(string $filter): void
    {
        $shape = $this->shape($filter);

        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): bool => (new ViewQuery())->contains(
                self::held($event, $shape),
                [new RegistrationKey($event, '00A0'), new RegistrationKey($event, '00A2')]
            )
        );
    }

    /**
     * S37: one aggregate statement with every kind, over each filter, stays inside its event.
     *
     * @dataProvider filters
     */
    public function testTheAggregatesStayInsideTheirEvent(string $filter): void
    {
        $shape = $this->shape($filter);
        $specs = [
            new AggregateSpec('code', AggregateKind::Rows),
            new AggregateSpec('field:module', AggregateKind::Members, ['a']),
            new AggregateSpec('decision:confirmation', AggregateKind::Members, ['confirmed']),
            new AggregateSpec('field:module', AggregateKind::Yes),
            new AggregateSpec('field:module', AggregateKind::Sum),
            new AggregateSpec('field:module', AggregateKind::Filled),
            new AggregateSpec('field:module', AggregateKind::Average),
        ];

        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): array => (new ViewQuery())->aggregates(self::held($event, $shape), $specs)
        );
    }

    /** S37: the aggregates of the master view, with a status member count, stay inside their event. */
    public function testTheMasterViewsAggregatesStayInsideTheirEvent(): void
    {
        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): array => (new ViewQuery())->aggregates(self::held($event, null), [
                new AggregateSpec('code', AggregateKind::Rows),
                new AggregateSpec('status', AggregateKind::Members, ['registered', 'waitlisted']),
            ])
        );
    }

    /**
     * Round 5: a narrowing of every kind and a search over every column, on the page, its total and the
     * aggregates, stays inside its event (the sorszám range numbers the event's own rows only).
     *
     * @dataProvider filters
     */
    public function testANarrowingStaysInsideItsEvent(string $filter): void
    {
        $shape = $this->shape($filter);
        $shape = ViewShape::of(json_decode($shape->filterJson(), true), [
            ['column' => 'status', 'write' => false],
            ...$shape->columns,
        ]);
        $narrowing = new Narrowing([
            ColumnFilter::of('status', FilterKind::Members, 'isAny', ['registered', 'waitlisted']),
            ColumnFilter::of('code', FilterKind::Text, 'contains', '0a'),
            ColumnFilter::of('serial', FilterKind::Range, 'between', [1, 5]),
            ColumnFilter::of('field:module', FilterKind::Members, 'isNone', ['c']),
            ColumnFilter::of('decision:confirmation', FilterKind::Members, 'isAny', ['confirmed', 'pending']),
            ColumnFilter::of(ColumnFilter::UNANSWERED, FilterKind::Unanswered, 'isAll', ['field:module']),
        ], [
            new SearchWord('a', ['status' => ['registered'], 'field:module' => ['a'], 'decision:confirmation' => ['pending']]),
        ]);
        $specs = [new AggregateSpec('code', AggregateKind::Rows), new AggregateSpec('field:module', AggregateKind::Filled)];

        $this->assertPerEventCost(
            fn (int $event) => $this->seedEvent($event),
            static fn (int $event): array => [
                (new ViewQuery())->page(self::held($event, $shape), null, false, 3, 1, narrowing: $narrowing),
                (new ViewQuery())->aggregates(self::held($event, $shape), $specs, $narrowing),
            ]
        );
    }
}
