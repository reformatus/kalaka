<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\View\CopyViews;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** S36-4 (hole A2), deleting a view and S36-8, copying the views of an event. */
final class ViewEditTest extends GrantTestCase
{
    private int $event;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        $this->event = $this->createEvent();
    }

    /**
     * @param list<array<string, mixed>> $filter
     * @return array<string, mixed>
     */
    private function edit(int $view, array $filter): array
    {
        return [
            'id' => $view,
            'version' => (int) $this->viewRow($view)['version'],
            'event_id' => $this->event,
            'name' => 'Nézet',
            'filter' => $filter,
            'columns' => [['column' => 'code', 'write' => false]],
        ];
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function widening(): iterable
    {
        yield 'the parameter removed' => [[]];
        yield 'the parameter made a fixed term' => [[['column' => 'field:module', 'in' => ['építő', 'konyha']]]];
        yield 'the parameter moved to another column' => [[['column' => 'field:team', 'parameter' => true]]];
    }

    /** @dataProvider widening */
    public function testAnEditThatWouldWidenABoundGrantIsRefused(array $filter): void
    {
        $view = $this->parameterView($this->event);
        $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);
        $before = $this->viewRow($view);

        try {
            $this->exec(new SaveView(), $this->edit($view, $filter));
            self::fail('The edit should have been refused.');
        } catch (RuleViolation) {
            self::assertSame($before, $this->viewRow($view));
        }
    }

    public function testAnEditThatKeepsTheParameterPasses(): void
    {
        $view = $this->parameterView($this->event);
        $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);

        $this->exec(new SaveView(), $this->edit($view, [
            ['column' => 'status', 'in' => ['registered']],
            ['column' => 'field:module', 'parameter' => true],
        ]));

        self::assertSame(
            '[{"column":"status","in":["registered"]},{"column":"field:module","parameter":true}]',
            $this->viewRow($view)['filter']
        );
    }

    public function testOnceTheBoundGrantIsDeletedTheParameterMayGo(): void
    {
        $view = $this->parameterView($this->event);
        $grant = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);
        $this->exec(new DeleteGrant(), ['id' => $grant]);

        $this->exec(new SaveView(), $this->edit($view, []));

        self::assertSame('[]', $this->viewRow($view)['filter']);
    }

    public function testAViewNoGrantUsesIsDeletedAndAudited(): void
    {
        $view = $this->plainView($this->event);
        $count = count($this->entries());

        $this->exec(new DeleteView(), ['id' => $view, 'version' => 1]);

        self::assertSame(0, $this->viewCount());
        $entries = $this->entries();
        self::assertCount($count + 1, $entries);
        self::assertSame('kalaka/delete-view', end($entries)['action']);
    }

    public function testAViewAGrantUsesIsNotDeleted(): void
    {
        $view = $this->plainView($this->event);
        $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view]);

        $this->expectException(RuleViolation::class);
        try {
            $this->exec(new DeleteView(), ['id' => $view, 'version' => 1]);
        } finally {
            self::assertSame(1, $this->viewCount());
        }
    }

    public function testDeletingAStaleOrMissingViewOrWithoutFullAccessChangesNothing(): void
    {
        $view = $this->plainView($this->event);

        foreach (
            [
                [VersionConflict::class, ['id' => $view, 'version' => 2], $this->admin],
                [NotFound::class, ['id' => $view + 1000, 'version' => 1], $this->admin],
                [PermissionDenied::class, ['id' => $view, 'version' => 1], new UserActor($this->plainUser())],
            ] as [$error, $input, $actor]
        ) {
            try {
                $this->exec(new DeleteView(), $input, $actor);
                self::fail($error . ' expected.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($error, $e);
                self::assertSame(1, $this->viewCount());
            }
        }
    }

    public function testCopyingViewsCopiesTheirShapesAndNoGrantsByDefault(): void
    {
        $plain = $this->plainView($this->event);
        $parameter = $this->parameterView($this->event);
        $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $parameter, 'bound_value' => 'építő']);
        $next = $this->createEvent('Jövő évi tábor');
        $grants = $this->grantCount();

        $copied = $this->exec(new CopyViews(), ['from_event_id' => $this->event, 'to_event_id' => $next]);

        self::assertIsArray($copied);
        self::assertSame([$plain, $parameter], array_keys($copied));
        foreach ($copied as $old => $new) {
            $from = $this->viewRow($old);
            $to = $this->viewRow($new);
            self::assertSame((string) $next, $to['event_id']);
            self::assertSame(
                [$from['name'], $from['filter'], $from['columns']],
                [$to['name'], $to['filter'], $to['columns']]
            );
        }
        self::assertSame($grants, $this->grantCount());
    }

    public function testCopyingViewsWithTheirGrantsPointsTheGrantsAtTheCopies(): void
    {
        $parameter = $this->parameterView($this->event);
        $leader = $this->plainUser();
        $this->saveGrant($this->event, $leader, ['view_id' => $parameter, 'bound_value' => 'építő']);
        $this->saveGrant($this->event, $leader, ['actions' => [self::WITHDRAW]]);
        $next = $this->createEvent('Jövő évi tábor');

        $copied = $this->exec(new CopyViews(), [
            'from_event_id' => $this->event,
            'to_event_id' => $next,
            'with_grants' => true,
        ]);

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, view_id, bound_value, actions FROM {$wpdb->prefix}kalaka_grants"
            . ' WHERE event_id = %d ORDER BY id',
            $next
        ), ARRAY_A);
        self::assertSame(
            [
                [
                    'user_id' => (string) $leader,
                    'view_id' => (string) $copied[$parameter],
                    'bound_value' => 'építő',
                    'actions' => '["' . self::EDIT . '"]',
                ],
                [
                    'user_id' => (string) $leader,
                    'view_id' => null,
                    'bound_value' => null,
                    'actions' => '["' . self::WITHDRAW . '"]',
                ],
            ],
            $rows
        );
    }

    public function testCopyingIntoTheSameOrAMissingEventIsRefused(): void
    {
        $this->plainView($this->event);
        $views = $this->viewCount();

        foreach (
            [
                [InvalidInput::class, ['from_event_id' => $this->event, 'to_event_id' => $this->event]],
                [NotFound::class, ['from_event_id' => $this->event, 'to_event_id' => 999999]],
            ] as [$error, $input]
        ) {
            try {
                $this->exec(new CopyViews(), $input);
                self::fail($error . ' expected.');
            } catch (\Throwable $e) {
                self::assertInstanceOf($error, $e);
                self::assertSame($views, $this->viewCount());
            }
        }
    }
}
