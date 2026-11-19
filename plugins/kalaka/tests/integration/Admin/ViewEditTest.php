<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\DuplicateGrant;
use Kalaka\Admin\View\CopyViews;
use Kalaka\Admin\View\DeleteView;
use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** S36-4 (owner ruling 2026-11-16, (c)): editing and deleting a view with grants; S36-8, copying the views of an event. */
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
    public static function parameterGone(): iterable
    {
        yield 'the parameter removed' => [[]];
        yield 'the parameter made a fixed term' => [[['column' => 'field:module', 'in' => ['építő', 'konyha']]]];
        yield 'the parameter moved to another column' => [[['column' => 'field:team', 'parameter' => true]]];
    }

    /**
     * A parameter change that would clear two grants of one user into equals is refused as `DuplicateGrant`:
     * the view and both grants stay as they were.
     *
     * @dataProvider parameterGone
     */
    public function testAParameterChangeThatWouldDuplicateAGrantIsRefusedAndChangesNothing(array $filter): void
    {
        $view = $this->parameterView($this->event);
        $user = $this->plainUser();
        $first = $this->saveGrant($this->event, $user, ['view_id' => $view, 'bound_value' => 'építő']);
        $second = $this->saveGrant($this->event, $user, ['view_id' => $view, 'bound_value' => 'konyha']);
        $filterBefore = $this->viewRow($view)['filter'];
        $versionBefore = $this->viewRow($view)['version'];
        $count = count($this->entries());

        try {
            $this->exec(new SaveView(), $this->edit($view, $filter));
            self::fail('The run should have thrown.');
        } catch (DuplicateGrant $error) {
            self::assertSame('kalaka_duplicate_grant', $error->errorCode());
        }

        self::assertSame($filterBefore, $this->viewRow($view)['filter']);
        self::assertSame($versionBefore, $this->viewRow($view)['version']);
        self::assertSame('építő', $this->grantRow($first)['bound_value']);
        self::assertSame('konyha', $this->grantRow($second)['bound_value']);
        self::assertCount($count, $this->entries());
    }

    public function testAParameterChangeThatDuplicatesNothingIsAccepted(): void
    {
        $view = $this->parameterView($this->event);
        $user = $this->plainUser();
        $first = $this->saveGrant($this->event, $user, ['view_id' => $view, 'bound_value' => 'építő']);
        $other = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);
        // The same user's grant of another view stays apart.
        $plain = $this->saveGrant($this->event, $user, ['view_id' => $this->plainView($this->event)]);

        $this->exec(new SaveView(), $this->edit($view, []));

        self::assertSame('[]', $this->viewRow($view)['filter']);
        self::assertNull($this->grantRow($first)['bound_value']);
        self::assertNull($this->grantRow($other)['bound_value']);
        self::assertNull($this->grantRow($plain)['bound_value']);
    }

    /**
     * S36-4 (owner ruling 2026-11-16, (c)): removing or moving the parameter is allowed; every bound grant
     * of the view loses its bound value, audited in the same entry, and its holder sees the whole view.
     *
     * @dataProvider parameterGone
     */
    public function testRemovingOrMovingTheParameterTakesTheBoundValuesAudited(array $filter): void
    {
        $view = $this->parameterView($this->event);
        $grant = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);
        $other = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'konyha']);
        $count = count($this->entries());

        $this->exec(new SaveView(), $this->edit($view, $filter));

        self::assertSame((string) wp_json_encode($filter, JSON_UNESCAPED_UNICODE), $this->viewRow($view)['filter']);
        self::assertNull($this->grantRow($grant)['bound_value']);
        self::assertNull($this->grantRow($other)['bound_value']);
        $entries = $this->entries();
        self::assertCount($count + 1, $entries);
        $entry = end($entries);
        self::assertSame('kalaka/save-view', $entry['action']);
        $subjects = $this->subjectsOf($entry['id']);
        self::assertContains("grant/{$this->event}/{$grant}", $subjects);
        self::assertContains("grant/{$this->event}/{$other}", $subjects);
    }

    public function testAnEditThatKeepsTheParameterKeepsTheBoundValues(): void
    {
        $view = $this->parameterView($this->event);
        $grant = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);

        $this->exec(new SaveView(), $this->edit($view, [
            ['column' => 'status', 'in' => ['registered']],
            ['column' => 'field:module', 'parameter' => true],
        ]));

        self::assertSame(
            '[{"column":"status","in":["registered"]},{"column":"field:module","parameter":true}]',
            $this->viewRow($view)['filter']
        );
        self::assertSame('építő', $this->grantRow($grant)['bound_value']);
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

    /** S36-4 (owner ruling 2026-11-16, (c)): the view's grants are deleted with it, each with its own entry. */
    public function testDeletingAViewDeletesItsGrantsEachAudited(): void
    {
        $view = $this->parameterView($this->event);
        $kept = $this->plainView($this->event);
        $first = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'építő']);
        $second = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $view, 'bound_value' => 'konyha']);
        $other = $this->saveGrant($this->event, $this->plainUser(), ['view_id' => $kept]);
        $count = count($this->entries());

        $this->exec(new DeleteView(), ['id' => $view, 'version' => 1]);

        self::assertSame(1, $this->viewCount());
        self::assertSame(1, $this->grantCount());
        self::assertSame((string) $kept, (string) $this->grantRow($other)['view_id']);
        $entries = array_slice($this->entries(), $count);
        $of = static fn (string $action): array => array_values(
            array_filter($entries, static fn (array $e): bool => $e['action'] === $action)
        );
        $views = $of('kalaka/delete-view');
        $grants = $of('kalaka/delete-grant');
        self::assertCount(1, $views);
        self::assertCount(2, $grants);
        $deleted = [];
        foreach ($grants as $entry) {
            self::assertSame($views[0]['id'], $entry['parent_id']);
            array_push($deleted, ...$this->subjectsOf($entry['id']));
        }
        sort($deleted);
        self::assertSame(["grant/{$this->event}/{$first}", "grant/{$this->event}/{$second}"], $deleted);
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
