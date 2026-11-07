<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Support\Directory\SaveDirectoryNode;

/** AC7 to AC11: saving a node. */
final class SaveDirectoryNodeTest extends SupportTestCase
{
    public function testANewRootIsCreatedAnAuditedAndSavingItAgainChangesNothing(): void
    {
        $actor = new CliActor(null);

        $created = $this->save([], $actor);

        self::assertSame('created', $created['outcome']);
        $row = $this->row($created['id']);
        self::assertSame(
            ['TEST-1', null, 'Felekezet', 'Teszt Egyház', null, '1', '1'],
            [
                $row['external_id'],
                $row['parent_id'],
                $row['type'],
                $row['name'],
                $row['aliases'],
                $row['active'],
                $row['version'],
            ]
        );

        $entry = $this->onlyEntry();
        self::assertSame('kalaka/save-directory-node', $entry['action']);
        self::assertSame('succeeded', $entry['outcome']);
        self::assertSame(['support_node//' . $created['id']], $this->subjectsOf($entry['id']));
        // The audit leaves out a column that stayed null (no parent, no aliases).
        self::assertSame(['external_id', 'type', 'name', 'active'], $this->changedFields($entry));

        $again = $this->save([], $actor);

        self::assertSame(['id' => $created['id'], 'outcome' => 'unchanged'], $again);
        self::assertSame('1', $this->row($created['id'])['version']);
        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame([], $this->changedFields($entries[1]));
        self::assertSame(['support_node//' . $created['id']], $this->subjectsOf($entries[1]['id']));
    }

    public function testANodeWithAParentAndAliasesHasAChangeItemForEveryColumnButIdAndVersion(): void
    {
        $this->save();
        $child = $this->save(['external_id' => 'TEST-2', 'parent_external_id' => 'TEST-1', 'aliases' => ['Másik név']]);

        $entries = $this->entries();

        self::assertSame(
            ['external_id', 'parent_id', 'type', 'name', 'aliases', 'active'],
            $this->changedFields($entries[1])
        );
        self::assertSame(['support_node//' . $child['id']], $this->subjectsOf($entries[1]['id']));
    }

    public function testAChangedNameIsAnUpdateWithOneChange(): void
    {
        $created = $this->save();

        $updated = $this->save(['name' => 'Teszt Egyház Új']);

        self::assertSame(['id' => $created['id'], 'outcome' => 'updated'], $updated);
        self::assertSame('2', $this->row($created['id'])['version']);
        $entries = $this->entries();
        self::assertSame(['name'], $this->changedFields($entries[1]));
    }

    public function testAliasesAreStoredJoinedByLineBreaksAndReadBack(): void
    {
        $id = $this->save(['aliases' => ['Első név', 'Második név']])['id'];

        self::assertSame("Első név\nMásodik név", $this->row($id)['aliases']);
        self::assertSame('unchanged', $this->save(['aliases' => ['Első név', 'Második név']])['outcome']);
        self::assertSame('updated', $this->save(['aliases' => []])['outcome']);
        self::assertNull($this->row($id)['aliases']);
    }

    public function testTheParentIsNamedByItsExternalIdAndStoredAsItsId(): void
    {
        $root = $this->save();

        $child = $this->save([
            'external_id' => 'TEST-2',
            'parent_external_id' => 'TEST-1',
            'type' => 'Egyházkerület',
            'name' => 'Kerület',
        ]);

        self::assertSame((string) $root['id'], $this->row($child['id'])['parent_id']);
    }

    public function testAnUnknownParentIsARuleViolationWithNothingStoredAndAFailedEntry(): void
    {
        try {
            $this->save(['parent_external_id' => 'NINCS']);
            self::fail('The parent should have been refused.');
        } catch (RuleViolation $e) {
            self::assertStringContainsString(
                'A megadott felettes szervezet nem szerepel a címtárban.',
                $e->getMessage()
            );
            self::assertSame(0, $this->nodeCount());
            $entry = $this->onlyEntry();
            self::assertSame('failed', $entry['outcome']);
        }
    }

    public function testANodeCannotBecomeItsOwnParentOrGoBelowItsDescendants(): void
    {
        $ids = $this->buildTree();
        $before = $this->row($ids['D1']);

        $moves = [
            'below one of its own congregations' => ['external_id' => 'D1', 'parent_external_id' => 'D1-A-1'],
            'below its own deanery' => ['external_id' => 'D1', 'parent_external_id' => 'D1-A'],
            'its own parent' => ['external_id' => 'D1', 'parent_external_id' => 'D1'],
        ];
        foreach ($moves as $label => $move) {
            try {
                $this->save($move + ['type' => 'Egyházkerület', 'name' => 'Északi Teszt Egyházkerület']);
                self::fail($label . ' should have been refused.');
            } catch (RuleViolation $e) {
                self::assertStringContainsString(
                    'Egy szervezet nem kerülhet önmaga vagy egy alá tartozó szervezet alá.',
                    $e->getMessage(),
                    $label
                );
                self::assertSame($before, $this->row($ids['D1']), $label);
            }
        }
    }

    public function testAMoveToAnotherParentIsAnUpdate(): void
    {
        $ids = $this->buildTree();

        $result = $this->save([
            'external_id' => 'D1-A',
            'parent_external_id' => 'D2',
            'type' => 'Egyházmegye',
            'name' => 'Budai Teszt Egyházmegye',
        ]);

        self::assertSame('updated', $result['outcome']);
        self::assertSame((string) $ids['D2'], $this->row($ids['D1-A'])['parent_id']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badInputs(): iterable
    {
        yield 'an empty name' => [['name' => '']];
        yield 'a 256-character external ID' => [['external_id' => str_repeat('a', 256)]];
        yield 'a non-ASCII external ID' => [['external_id' => 'ádam-1']];
        yield 'an external ID with a space' => [['external_id' => 'teszt 1']];
        yield 'a non-ASCII parent' => [['parent_external_id' => 'ádam-1']];
        yield 'a 101-character type' => [['type' => str_repeat('a', 101)]];
        yield 'an alias with a line break' => [['aliases' => ["egy\nkettő"]]];
        yield 'an alias ending in a line break' => [['aliases' => ["egy\n"]]];
        yield '21 aliases' => [['aliases' => array_map(static fn (int $i): string => 'név ' . $i, range(1, 21))]];
        yield 'an empty alias' => [['aliases' => ['']]];
        yield 'an empty parent' => [['parent_external_id' => '']];
        yield 'a non-boolean active' => [['active' => 'x']];
    }

    /**
     * @dataProvider badInputs
     * @param array<string, mixed> $overrides
     */
    public function testInputTheSchemaRefusesIsInvalidWithNothingStoredAndNoEntry(array $overrides): void
    {
        try {
            $this->save($overrides);
            self::fail('The input should have been refused.');
        } catch (InvalidInput) {
            self::assertSame(0, $this->nodeCount());
            self::assertSame([], $this->entries());
        }
    }

    public function testTheLimitsThemselvesAreAccepted(): void
    {
        $aliases = array_map(static fn (int $i): string => str_repeat('á', 191), range(1, 20));

        $id = $this->save([
            'external_id' => str_repeat('a', 255),
            'type' => str_repeat('í', 100),
            'name' => str_repeat('ő', 191),
            'aliases' => $aliases,
        ])['id'];

        self::assertSame(str_repeat('ő', 191), $this->row($id)['name']);
        self::assertSame(str_repeat('a', 255), $this->row($id)['external_id']);
    }

    public function testTheParentIsReadForUpdateSoOppositeMovesSerialize(): void
    {
        $this->save(['external_id' => 'TEST-1']);

        $statements = $this->statementsDuring(function (): void {
            $this->save(['external_id' => 'TEST-2', 'parent_external_id' => 'TEST-1']);
        });

        $parentReads = array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, "external_id = 'TEST-1'")
        );
        self::assertCount(1, $parentReads);
        self::assertStringEndsWith('FOR UPDATE', trim((string) reset($parentReads)));
    }

    public function testAUserWithoutFullAccessIsRefusedAndAuditedAndOthersSucceed(): void
    {
        $user = new UserActor(self::factory()->user->create());

        try {
            $this->save([], $user);
            self::fail('The user should have been refused.');
        } catch (PermissionDenied) {
            self::assertSame(0, $this->nodeCount());
            self::assertSame('failed', $this->onlyEntry()['outcome']);
        }

        $this->save(['external_id' => 'A'], $this->admin);
        $this->save(['external_id' => 'B'], new CliActor($this->fullAccessUser()));
        self::assertSame(2, $this->nodeCount());
    }

    public function testCaseDistinctExternalIdsAreDifferentNodes(): void
    {
        $first = $this->save(['external_id' => 'Adam-1']);

        $second = $this->save(['external_id' => 'adam-1', 'name' => 'Másik név']);

        self::assertSame('created', $second['outcome']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('Adam-1', $this->row($first['id'])['external_id']);
        self::assertSame('adam-1', $this->row($second['id'])['external_id']);
        self::assertSame(2, $this->nodeCount());
    }

    public function testDeactivatingKeepsTheRowAndEverythingElse(): void
    {
        $ids = $this->buildTree();
        $before = $this->row($ids['D1-B-1']);

        $result = $this->save([
            'external_id' => 'D1-B-1',
            'parent_external_id' => 'D1-B',
            'type' => 'Gyülekezet',
            'name' => 'Teszt Fasori Gyülekezet',
            'aliases' => ['Teszt Fasor Kápolna'],
            'active' => false,
        ]);

        self::assertSame('updated', $result['outcome']);
        $after = $this->row($ids['D1-B-1']);
        self::assertSame('0', $after['active']);
        foreach (['parent_id', 'name', 'aliases', 'external_id', 'type'] as $column) {
            self::assertSame($before[$column], $after[$column], $column);
        }
        self::assertSame(20, $this->nodeCount());
    }

    public function testNoFileOfThePluginDeletesOrDropsAnything(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            dirname(__DIR__, 3),
            \FilesystemIterator::SKIP_DOTS
        ));
        $checked = 0;
        foreach ($files as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php') || str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }
            $checked++;
            self::assertSame(
                0,
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a plugin source file
                preg_match('/\b(DELETE|TRUNCATE|DROP)\b|->delete\(/', (string) file_get_contents($path)),
                $path
            );
        }
        self::assertGreaterThan(5, $checked);
    }
}
