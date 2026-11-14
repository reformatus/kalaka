<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

use Kalaka\Admin\View\SaveView;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\VersionConflict;

/** AC3 and AC4: saving a view. */
final class SaveViewTest extends AdminTestCase
{
    private const FILTER = '[{"column":"status","in":["registered","waitlisted"]},'
        . '{"column":"field:module","parameter":true}]';

    private const COLUMNS = '[{"column":"code","write":false},{"column":"field:module","write":true},'
        . '{"column":"decision:confirmation","write":false}]';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(int $event, array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $event,
            'name' => 'Résztvevők',
            'filter' => [
                ['column' => 'status', 'in' => ['registered', 'waitlisted']],
                ['column' => 'field:module', 'parameter' => true],
            ],
            'columns' => [
                ['column' => 'code', 'write' => false],
                ['column' => 'field:module', 'write' => true],
                ['column' => 'decision:confirmation', 'write' => false],
            ],
        ];
    }

    private function save(int $event): int
    {
        $id = $this->exec(new SaveView(), $this->input($event));
        self::assertIsInt($id);

        return $id;
    }

    public function testANewViewIsStoredWithVersionOneAndOneAuditEntry(): void
    {
        $event = $this->createEvent();

        $id = $this->save($event);

        $row = $this->viewRow($id);
        self::assertSame(
            [(string) $event, '1', 'Résztvevők', self::FILTER, self::COLUMNS],
            [$row['event_id'], $row['version'], $row['name'], $row['filter'], $row['columns']]
        );

        $entries = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === 'kalaka/save-view'
        ));
        self::assertCount(1, $entries);
        self::assertSame("view/{$event}/{$id}", $this->subjectsOf($entries[0]['id'])[0]);
        $changes = [];
        foreach ($this->decode($entries[0]['changes']) as $change) {
            self::assertIsArray($change);
            $changes[(string) $change['field']] = $change['after'];
        }
        self::assertSame('Résztvevők', $changes['name']);
        self::assertSame(self::FILTER, $changes['filter']);
        self::assertSame(self::COLUMNS, $changes['columns']);
    }

    public function testAnUpdateChangesTheViewAndItsVersionAndAuditsOnlyWhatChanged(): void
    {
        $event = $this->createEvent();
        $id = $this->save($event);

        $this->exec(new SaveView(), $this->input($event, ['id' => $id, 'version' => 1, 'name' => 'Másik név']));

        $row = $this->viewRow($id);
        self::assertSame(['2', 'Másik név', self::FILTER, self::COLUMNS], [
            $row['version'], $row['name'], $row['filter'], $row['columns'],
        ]);

        $entries = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === 'kalaka/save-view'
        ));
        self::assertCount(2, $entries);
        $changes = $this->decode($entries[1]['changes']);
        self::assertCount(1, $changes);
        $change = $changes[0];
        self::assertIsArray($change);
        self::assertSame(['name', 'Résztvevők', 'Másik név'], [$change['field'], $change['before'], $change['after']]);
    }

    public function testAnUpdateWithTheShapeInAnotherKeyOrderChangesNothingInTheShape(): void
    {
        $event = $this->createEvent();
        $id = $this->save($event);

        $this->exec(new SaveView(), $this->input($event, [
            'id' => $id,
            'version' => 1,
            'name' => 'Új',
            'filter' => [
                ['in' => ['registered', 'waitlisted'], 'column' => 'status'],
                ['parameter' => true, 'column' => 'field:module'],
            ],
            'columns' => [
                ['write' => false, 'column' => 'code'],
                ['write' => true, 'column' => 'field:module'],
                ['write' => false, 'column' => 'decision:confirmation'],
            ],
        ]));

        $entries = array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === 'kalaka/save-view'
        ));
        $changes = $this->decode($entries[1]['changes']);
        self::assertCount(1, $changes);
        self::assertIsArray($changes[0]);
        self::assertSame('name', $changes[0]['field']);
    }

    public function testAStaleVersionIsAVersionConflictAndChangesNothing(): void
    {
        $event = $this->createEvent();
        $id = $this->save($event);
        $this->exec(new SaveView(), $this->input($event, ['id' => $id, 'version' => 1, 'name' => 'Második']));

        try {
            $this->exec(new SaveView(), $this->input($event, ['id' => $id, 'version' => 1, 'name' => 'Harmadik']));
            self::fail('The run should have thrown.');
        } catch (VersionConflict) {
            self::assertSame(['2', 'Második'], [$this->viewRow($id)['version'], $this->viewRow($id)['name']]);
        }
    }

    public function testAnUpdateOfAViewOfAnotherEventOrOfNoViewIsNotFound(): void
    {
        $event = $this->createEvent();
        $other = $this->createEvent('Másik');
        $id = $this->save($event);

        foreach ([[$other, $id], [$event, $id + 1000]] as [$inEvent, $viewId]) {
            try {
                $this->exec(new SaveView(), $this->input($inEvent, ['id' => $viewId, 'version' => 1, 'name' => 'Új']));
                self::fail('The run should have thrown.');
            } catch (NotFound) {
                self::assertSame('Résztvevők', $this->viewRow($id)['name']);
            }
        }
    }

    public function testAnIdWithoutAVersionIsInvalid(): void
    {
        $event = $this->createEvent();
        $id = $this->save($event);

        $this->expectException(InvalidInput::class);
        $this->exec(new SaveView(), $this->input($event, ['id' => $id]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        $column = static fn (string $id, bool $write = false): array => [['column' => $id, 'write' => $write]];

        yield 'an unknown column id' => [['columns' => $column('nonsense')]];
        yield 'a field key in capitals' => [['columns' => $column('field:Module')]];
        yield 'a field key starting with a digit' => [['columns' => $column('field:1module')]];
        yield 'an empty field key' => [['columns' => $column('field:')]];
        yield 'a field key of 65 characters' => [['columns' => $column('field:a' . str_repeat('b', 64))]];
        yield 'a field key with a trailing newline' => [['columns' => $column("field:module\n")]];
        yield 'a decision kind in capitals' => [['columns' => $column('decision:Hold')]];
        yield 'an empty decision kind' => [['columns' => $column('decision:')]];
        yield 'a decision kind of 51 characters' => [['columns' => $column('decision:a' . str_repeat('b', 50))]];
        yield 'write on code' => [['columns' => $column('code', true)]];
        yield 'write on status' => [['columns' => $column('status', true)]];
        yield 'write on serial' => [['columns' => $column('serial', true)]];
        yield 'write on a decision column' => [['columns' => $column('decision:confirmation', true)]];
        yield 'the same column twice' => [['columns' => [...$column('code'), ...$column('code')]]];
        yield 'an empty column list' => [['columns' => []]];
        yield 'a column without write' => [['columns' => [['column' => 'code']]]];
        yield 'a filter term on code' => [['filter' => [['column' => 'code', 'in' => ['ABCD']]]]];
        yield 'a filter term on serial' => [['filter' => [['column' => 'serial', 'in' => ['1']]]]];
        yield 'a term with an empty in' => [['filter' => [['column' => 'status', 'in' => []]]]];
        yield 'a term whose in holds a non-string' => [['filter' => [['column' => 'field:module', 'in' => ['a', 5]]]]];
        yield 'a term with both in and parameter' => [
            ['filter' => [['column' => 'field:module', 'in' => ['a'], 'parameter' => true]]],
        ];
        yield 'a status term naming a non-status' => [
            ['filter' => [['column' => 'status', 'in' => ['registered', 'late']]]],
        ];
        yield 'two parameter terms' => [
            ['filter' => [
                ['column' => 'field:module', 'parameter' => true],
                ['column' => 'status', 'parameter' => true],
            ]],
        ];
        yield 'an empty name' => [['name' => '']];
        yield 'a blank name' => [['name' => "  \t "]];
        yield 'a name of 192 characters' => [['name' => str_repeat('á', 192)]];
    }

    /**
     * @dataProvider invalidInputs
     * @param array<string, mixed> $overrides
     */
    public function testInvalidInputIsRefusedAndNothingIsStored(array $overrides): void
    {
        $event = $this->createEvent();

        try {
            $this->exec(new SaveView(), $this->input($event, $overrides));
            self::fail('The run should have thrown.');
        } catch (InvalidInput) {
            self::assertSame(0, $this->viewCount());
        }
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        $event = $this->createEvent();

        $id = $this->exec(new SaveView(), $this->input($event, [
            'name' => str_repeat('á', 191),
            'filter' => [],
            'columns' => [
                ['column' => 'field:' . 'a' . str_repeat('b', 63), 'write' => true],
                ['column' => 'decision:a' . str_repeat('b', 49), 'write' => false],
            ],
        ]));

        self::assertIsInt($id);
        self::assertSame('[]', $this->viewRow($id)['filter']);
    }

    public function testAnEventThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        try {
            $this->exec(new SaveView(), $this->input(999999));
        } finally {
            self::assertSame(0, $this->viewCount());
        }
    }

    public function testWithoutFullAccessTheUseCaseIsRefused(): void
    {
        $event = $this->createEvent();
        $editor = new UserActor(self::factory()->user->create(['role' => 'editor']));

        $this->expectException(PermissionDenied::class);
        try {
            $this->exec(new SaveView(), $this->input($event), $editor);
        } finally {
            self::assertSame(0, $this->viewCount());
        }
    }
}
