<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Entry\CommandFailure;
use Kalaka\Support\Directory\Cli\DirectoryImport;

/**
 * AC20: the import, free of WP-CLI. The files have one column per level and are synthetic; they
 * live in a temporary folder.
 */
final class DirectoryImportTest extends SupportTestCase
{
    private string $folder = '';

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function set_up(): void
    {
        parent::set_up();
        $this->folder = sys_get_temp_dir() . '/kalaka-import-' . uniqid('', true);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a test fixture on the local temp directory
        mkdir($this->folder);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case and WP-CLI name this hook in snake case
    public function tear_down(): void
    {
        if ($this->folder !== '') {
            foreach (glob($this->folder . '/*') ?: [] as $file) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a test fixture on the local temp directory
                unlink($file);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- a test fixture on the local temp directory
            rmdir($this->folder);
        }
        parent::tear_down();
    }

    private function file(string $content, string $name = 'import.csv'): string
    {
        $path = $this->folder . '/' . $name;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture on the local temp directory
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @return array{
     *     entries: list<array{line: int, external_id: string, implied: bool, outcome: string, message: ?string}>,
     *     unmentioned: list<array{external_id: string, name: string}>
     * }
     */
    private function import(string $path, string $delimiter = ',', ?CliActor $actor = null): array
    {
        return (new DirectoryImport($this->runner))->run($path, $delimiter, $actor ?? new CliActor(null));
    }

    /**
     * @param array{entries: list<array<string, mixed>>} $report
     * @return list<string>
     */
    private function outcomes(array $report): array
    {
        return array_column($report['entries'], 'outcome');
    }

    /**
     * @param array{entries: list<array<string, mixed>>} $report
     * @return list<string>
     */
    private function externalIds(array $report): array
    {
        return array_column($report['entries'], 'external_id');
    }

    private function auditCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}kalaka_audit_entries");
    }

    private function bigFile(): string
    {
        $lines = ['Egyház,Egyházkerület,Egyházmegye,Gyülekezet,id'];
        $lines[] = 'Teszt Egyház,,,,';
        for ($d = 1; $d <= 4; $d++) {
            $lines[] = "Teszt Egyház,Teszt Kerület {$d},,,";
            for ($e = 1; $e <= 5; $e++) {
                $lines[] = "Teszt Egyház,Teszt Kerület {$d},Teszt Megye {$d}-{$e},,";
            }
        }
        for ($c = 1; $c <= 1300; $c++) {
            $d = ($c % 4) + 1;
            $e = ($c % 5) + 1;
            $lines[] = "Teszt Egyház,Teszt Kerület {$d},Teszt Megye {$d}-{$e},Teszt Gyülekezet {$c},teszt:{$c}";
        }

        return $this->file(implode("\n", $lines) . "\n");
    }

    public function testThirteenHundredCongregationsAreCreatedAndThenAreUnchangedWithoutAuditEntries(): void
    {
        $path = $this->bigFile();
        $start = $this->auditCount();

        $first = $this->import($path);
        $audited = $this->auditCount();
        $second = $this->import($path);

        self::assertCount(1 + 4 + 20 + 1300, $first['entries']);
        self::assertSame(['created'], array_values(array_unique($this->outcomes($first))));
        self::assertSame([false], array_values(array_unique(array_column($first['entries'], 'implied'))));
        self::assertSame(['unchanged'], array_values(array_unique($this->outcomes($second))));
        self::assertSame(1325, $this->nodeCount());
        self::assertSame($start + 1325, $audited);
        self::assertSame($audited, $this->auditCount());
        self::assertSame([], $second['unmentioned']);
        self::assertSame(
            (string) $this->idOf('teszt_egyhaz/teszt_kerulet_2/teszt_megye_2_2'),
            $this->row($this->idOf('teszt:1'))['parent_id']
        );
    }

    public function testOnlyTheChangedRowRunsTheUseCaseAndWritesAnAuditEntry(): void
    {
        $this->import($this->file(
            "Egyház,Egyházmegye\nTeszt Egyház,\nTeszt Egyház,Teszt Megye A\nTeszt Egyház,Teszt Megye B\n"
        ));
        $before = $this->auditCount();

        $report = $this->import($this->file(
            "Egyház,Egyházmegye,aliases\nTeszt Egyház,,\nTeszt Egyház,Teszt Megye A,Teszt Álnév\n"
            . "Teszt Egyház,Teszt Megye B,\n"
        ));

        self::assertSame(['unchanged', 'updated', 'unchanged'], $this->outcomes($report));
        self::assertSame($before + 1, $this->auditCount());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedFiles(): iterable
    {
        yield 'a level column after a reserved one' => ["Egyház,id,Egyházmegye\nA,,B\n", 'Unknown column: Egyházmegye'];
        yield 'a duplicate level column' => ["Egyház,Egyház\nA,B\n", 'Duplicate column: Egyház'];
        yield 'a duplicate reserved column' => ["Egyház,id,id\nA,,\n", 'Duplicate column: id'];
        yield 'an empty header cell' => ["Egyház,,Egyházmegye\nA,,B\n", 'empty column name in column 2'];
        yield 'no level column' => ["id,aliases\na,\n", 'no level column'];
        yield 'the wrong delimiter' => ["Egyház;Egyházmegye\nA;B\n", 'check the delimiter'];
        yield 'an empty file' => ['', 'The file has no header row.'];
        yield 'only blank lines' => ["\n  \t\n\n", 'The file has no header row.'];
        yield 'invalid UTF-8' => ["Egyház\nA\xE9\n", 'The file is not valid UTF-8.'];
    }

    /** @dataProvider refusedFiles */
    public function testAFileTheReaderRefusesIsRefusedBeforeAnyNodeIsSaved(string $content, string $reason): void
    {
        try {
            $this->import($this->file($content));
            self::fail('The file should have been refused.');
        } catch (CommandFailure $e) {
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertSame(0, $this->nodeCount());
        }
    }

    public function testAMissingFileAndABadDelimiterAreRefused(): void
    {
        foreach ([[$this->folder . '/nincs.csv', ','], [$this->file("a\n"), ',,']] as [$path, $delimiter]) {
            try {
                $this->import($path, $delimiter);
                self::fail('Should have been refused.');
            } catch (CommandFailure) {
                self::assertSame(0, $this->nodeCount());
            }
        }
    }

    public function testABomPipeSeparatedAliasesActiveZeroAndReservedColumnsInAnyOrderAreRead(): void
    {
        $path = $this->file(
            "\xEF\xBB\xBFEgyház,Egyházkerület,active,aliases,id\n"
            . "\"Teszt, Egyház\",,,,\n"
            . "\"Teszt, Egyház\",Teszt Kerület,0,Első név | Második név,\n"
        );

        $report = $this->import($path);

        self::assertSame(['created', 'created'], $this->outcomes($report));
        self::assertSame(['teszt_egyhaz', 'teszt_egyhaz/teszt_kerulet'], $this->externalIds($report));
        $root = $this->row($this->idOf('teszt_egyhaz'));
        self::assertSame('Teszt, Egyház', $root['name']);
        self::assertSame('Egyház', $root['type']);
        self::assertSame('1', $root['active']);
        $district = $this->row($this->idOf('teszt_egyhaz/teszt_kerulet'));
        self::assertSame('Egyházkerület', $district['type']);
        self::assertSame('0', $district['active']);
        self::assertSame("Első név\nMásodik név", $district['aliases']);
        self::assertSame((string) $this->idOf('teszt_egyhaz'), $district['parent_id']);
    }

    public function testASemicolonDelimiterIsReadAndAQuotedCellMaySpanLines(): void
    {
        $path = $this->file("Egyház;id\n\"Teszt\nEgyház\";r\n");

        $report = $this->import($path, ';');

        self::assertSame(['created'], $this->outcomes($report));
        self::assertSame("Teszt\nEgyház", $this->row($this->idOf('r'))['name']);
    }

    public function testTheReportedLineIsTheFileLineWhereTheRowStartsEvenAfterAMultiLineCell(): void
    {
        $path = $this->file(
            "Egyház,Egyházkerület,id\n"
            . "\"Teszt\nEgyház\nhárom sorban\",,R\n"
            . "\n"
            . "   \n"
            . "Teszt Egyház,Teszt Kerület 1,\r\n"
            . "rossz,sor\n"
            . "Teszt Egyház,\"Teszt\nKerület 2\",\n"
            . "rossz,sor\n"
        );

        $report = $this->import($path);

        self::assertSame(['created', 'created', 'created', 'failed', 'created', 'failed'], $this->outcomes($report));
        self::assertSame([2, 7, 7, 8, 9, 11], array_column($report['entries'], 'line'));
        self::assertSame([false, true, false, false, false, false], array_column($report['entries'], 'implied'));
        self::assertSame('Found 2 cells, expected 3.', $report['entries'][3]['message']);
    }

    public function testAWhitespaceOnlyLineIsSkippedLikeABlankLine(): void
    {
        $report = $this->import($this->file("Egyház\nTeszt Egyház\n   \t \nTeszt Másik\n"));

        self::assertSame(['created', 'created'], $this->outcomes($report));
        self::assertSame([2, 4], array_column($report['entries'], 'line'));
    }

    public function testAnActiveValueOfXFailsThatRowWithInvalidInput(): void
    {
        $report = $this->import($this->file("Egyház,active\nTeszt Egyház,x\nTeszt Másik,1\n"));

        self::assertSame(['failed', 'created'], $this->outcomes($report));
        self::assertStringContainsString('Hibás vagy hiányzó adat.', (string) $report['entries'][0]['message']);
        self::assertSame(1, $this->nodeCount());
    }

    public function testARowWithTheWrongNumberOfCellsOrAnEmptyFirstLevelFailsAlone(): void
    {
        $report = $this->import($this->file("Egyház,Egyházkerület\nTeszt Egyház\n,Teszt Kerület\nTeszt Másik,\n\n"));

        self::assertSame(['failed', 'failed', 'created'], $this->outcomes($report));
        self::assertSame('The first level cell is empty.', $report['entries'][1]['message']);
        self::assertSame(1, $this->nodeCount());
    }

    public function testEveryRowFailsWithPermissionDeniedForAUserWithoutFullAccess(): void
    {
        $path = $this->file("Egyház\nTeszt Egyház\n");
        $user = self::factory()->user->create();

        $report = (new DirectoryImport($this->runner))->run($path, ',', new CliActor($user));

        self::assertSame(['failed'], $this->outcomes($report));
        self::assertSame('Ehhez nincs jogosultságod.', $report['entries'][0]['message']);
        self::assertSame(0, $this->nodeCount());
    }

    /** @return iterable<string, array{string, string}> */
    public static function slugs(): iterable
    {
        yield 'accents and spaces' => ['Ádám egy kettő', 'adam_egy_ketto'];
        yield 'ő and ű' => ['Őrségi Űrhajó', 'orsegi_urhajo'];
        yield 'a hyphen' => ['Szabolcs-Beregi', 'szabolcs_beregi'];
        yield 'a roman numeral' => ['Brassó I', 'brasso_i'];
        yield 'runs and ends trimmed' => ['  --Teszt, (Egyház)--  ', 'teszt_egyhaz'];
        yield 'no letter or digit' => ['--- ! ---', ''];
    }

    /** @dataProvider slugs */
    public function testTheSlugOfAName(string $name, string $slug): void
    {
        self::assertSame($slug, DirectoryImport::slug($name));
    }

    public function testDerivedIdsChainTheSlugsAndANameWithoutLettersFails(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházkerület\nTeszt Egyház,Szabolcs-Beregi\nTeszt Egyház,Brassó I\n---,\n"
        ));

        self::assertSame(['created', 'created', 'created', 'failed'], $this->outcomes($report));
        self::assertSame(
            ['teszt_egyhaz', 'teszt_egyhaz/szabolcs_beregi', 'teszt_egyhaz/brasso_i', ''],
            $this->externalIds($report)
        );
        self::assertStringContainsString('no letter or digit', (string) $report['entries'][3]['message']);
    }

    public function testASkippedLevelHangsTheNodeUnderTheNearestFilledCell(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházkerület,Egyházmegye,Gyülekezet\n"
            . "Teszt Egyház,,,Teszt Gyülekezet 1\n"
            . "Teszt Egyház,Teszt Kerület,,Teszt Gyülekezet 2\n"
        ));

        self::assertSame(['created', 'created', 'created', 'created'], $this->outcomes($report));
        self::assertSame(
            [
                'teszt_egyhaz',
                'teszt_egyhaz/teszt_gyulekezet_1',
                'teszt_egyhaz/teszt_kerulet',
                'teszt_egyhaz/teszt_kerulet/teszt_gyulekezet_2',
            ],
            $this->externalIds($report)
        );
        self::assertSame([true, false, true, false], array_column($report['entries'], 'implied'));
        $first = $this->row($this->idOf('teszt_egyhaz/teszt_gyulekezet_1'));
        self::assertSame((string) $this->idOf('teszt_egyhaz'), $first['parent_id']);
        self::assertSame('Gyülekezet', $first['type']);
        self::assertSame(
            (string) $this->idOf('teszt_egyhaz/teszt_kerulet'),
            $this->row($this->idOf('teszt_egyhaz/teszt_kerulet/teszt_gyulekezet_2'))['parent_id']
        );
    }

    public function testImpliedAncestorsAreCreatedOnceActiveWithoutAliases(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházkerület,Gyülekezet\n"
            . "Teszt Egyház,Teszt Kerület,Teszt Gyülekezet A\n"
            . "Teszt Egyház,Teszt Kerület,Teszt Gyülekezet B\n"
        ));

        self::assertSame([2, 2, 2, 3], array_column($report['entries'], 'line'));
        self::assertSame([true, true, false, false], array_column($report['entries'], 'implied'));
        self::assertSame(['created', 'created', 'created', 'created'], $this->outcomes($report));
        self::assertSame(4, $this->nodeCount());
        $district = $this->row($this->idOf('teszt_egyhaz/teszt_kerulet'));
        self::assertSame('Egyházkerület', $district['type']);
        self::assertSame('Teszt Kerület', $district['name']);
        self::assertSame('1', $district['active']);
        self::assertNull($district['aliases']);
    }

    public function testAStoredImpliedAncestorIsLeftUntouched(): void
    {
        $id = $this->save([
            'external_id' => 'teszt_egyhaz',
            'type' => 'Felekezet',
            'name' => 'Teszt Egyház',
            'aliases' => ['Teszt Régi Név'],
            'active' => false,
        ])['id'];
        $before = $this->row($id);
        $audited = $this->auditCount();

        $report = $this->import($this->file("Egyház,Egyházkerület\nTeszt Egyház,Teszt Kerület\n"));

        self::assertSame(['unchanged', 'created'], $this->outcomes($report));
        self::assertSame([true, false], array_column($report['entries'], 'implied'));
        self::assertSame($before, $this->row($id));
        self::assertSame($audited + 1, $this->auditCount());
    }

    public function testAnAncestorsRowAfterItsChildrenWins(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházkerület,aliases\nTeszt Egyház,Teszt Kerület,\nTeszt Egyház,,Teszt Álnév\n"
        ));

        self::assertSame([2, 3], array_column($report['entries'], 'line'));
        self::assertSame([false, false], array_column($report['entries'], 'implied'));
        self::assertSame(['created', 'created'], $this->outcomes($report));
        self::assertSame('Teszt Álnév', $this->row($this->idOf('teszt_egyhaz'))['aliases']);
        self::assertSame(2, $this->nodeCount());
    }

    public function testARowWithAnIdGivesItsChildrenDerivedIds(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházmegye,Gyülekezet,id\n"
            . "Teszt Egyház,,,mre:1\n"
            . "Teszt Egyház,Teszt Megye,,mre:em:26\n"
            . "Teszt Egyház,Teszt Megye,Komoró,\n"
        ));

        self::assertSame(['mre:1', 'mre:em:26', 'mre:em:26/komoro'], $this->externalIds($report));
        self::assertSame(['created', 'created', 'created'], $this->outcomes($report));
        self::assertSame((string) $this->idOf('mre:1'), $this->row($this->idOf('mre:em:26'))['parent_id']);
    }

    public function testARenamedParentWithAnIdKeepsItsChildren(): void
    {
        $this->import($this->file("Egyház,Egyházmegye,id\nTeszt Egyház,,mre:1\nTeszt Egyház,Teszt Megye,\n"));
        $root = $this->idOf('mre:1');
        $child = $this->idOf('mre:1/teszt_megye');

        $report = $this->import($this->file(
            "Egyház,Egyházmegye,id\nTeszt Egyház Új,,mre:1\nTeszt Egyház Új,Teszt Megye,\n",
            'renamed.csv'
        ));

        self::assertSame(['updated', 'unchanged'], $this->outcomes($report));
        self::assertSame($root, $this->idOf('mre:1'));
        self::assertSame($child, $this->idOf('mre:1/teszt_megye'));
        self::assertSame('Teszt Egyház Új', $this->row($root)['name']);
        self::assertSame(2, $this->nodeCount());
    }

    public function testSameNamedSiblingsAreRefusedAndNothingIsSuffixed(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházmegye\nTeszt Egyház,Teszt Gyülekezet\nTeszt Egyház,Teszt-Gyülekezet\n"
        ));

        self::assertSame(['created', 'failed', 'failed'], $this->outcomes($report));
        foreach ([1, 2] as $entry) {
            self::assertSame(
                'The external ID teszt_egyhaz/teszt_gyulekezet belongs to more than one node, on lines 2, 3.',
                $report['entries'][$entry]['message']
            );
        }
        self::assertSame(1, $this->nodeCount());
    }

    public function testAnIdClashingWithADerivedIdFailsBothRows(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházmegye,id\nTeszt Egyház,,\nTeszt Egyház,Teszt Megye,\nTeszt Másik,,teszt_egyhaz/teszt_megye\n"
        ));

        self::assertSame(['created', 'failed', 'failed'], $this->outcomes($report));
        self::assertStringContainsString(
            'teszt_egyhaz/teszt_megye belongs to more than one node, on lines 3, 4',
            (string) $report['entries'][1]['message']
        );
        self::assertSame($report['entries'][1]['message'], $report['entries'][2]['message']);
        self::assertSame(1, $this->nodeCount());
    }

    public function testDuplicateLabelPathsFailBothLinesAndTheChildrenBelow(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházmegye,Gyülekezet\n"
            . "Teszt Egyház,Teszt Megye,\n"
            . "Teszt Egyház,Teszt Megye,\n"
            . "Teszt Egyház,Teszt Megye,Teszt Gyülekezet\n"
        ));

        self::assertSame([2, 2, 3, 4], array_column($report['entries'], 'line'));
        self::assertSame(['created', 'failed', 'failed', 'failed'], $this->outcomes($report));
        self::assertStringStartsWith('Duplicate row', (string) $report['entries'][1]['message']);
        self::assertStringStartsWith('Duplicate row', (string) $report['entries'][2]['message']);
        self::assertSame('The parent on line 2 failed.', $report['entries'][3]['message']);
        self::assertSame(1, $this->nodeCount());
    }

    public function testANonAsciiIdFailsItsRowAndItsChildrenOnly(): void
    {
        $report = $this->import($this->file(
            "Egyház,Egyházmegye,id\nTeszt Egyház,,ádám\nTeszt Egyház,Teszt Megye,\nTeszt Másik,,\n"
        ));

        self::assertSame(['failed', 'failed', 'created'], $this->outcomes($report));
        self::assertStringContainsString('Hibás vagy hiányzó adat.', (string) $report['entries'][0]['message']);
        self::assertSame('The parent on line 2 failed.', $report['entries'][1]['message']);
        self::assertSame(1, $this->nodeCount());
    }

    public function testActiveStoredNodesTheFileLeavesOutAreReportedAndInactiveOnesAreNot(): void
    {
        $this->import($this->file(
            "Egyház,Egyházmegye,Gyülekezet,active\n"
            . "Teszt Egyház,Teszt Megye,Teszt Gyülekezet A,\n"
            . "Teszt Egyház,Teszt Megye,Teszt Gyülekezet B,\n"
            . "Teszt Egyház,Teszt Megye,Teszt Gyülekezet C,0\n"
            . "Teszt Másik,Teszt Megye X,,\n"
        ));

        $report = $this->import($this->file(
            "Egyház,Egyházmegye,Gyülekezet\nTeszt Egyház,Teszt Megye,Teszt Gyülekezet A\n",
            'partial.csv'
        ));

        self::assertSame(['unchanged', 'unchanged', 'unchanged'], $this->outcomes($report));
        self::assertSame(
            [['external_id' => 'teszt_egyhaz/teszt_megye/teszt_gyulekezet_b', 'name' => 'Teszt Gyülekezet B']],
            $report['unmentioned']
        );
        self::assertSame('1', $this->row($this->idOf('teszt_egyhaz/teszt_megye/teszt_gyulekezet_b'))['active']);
    }

    private function idOf(string $externalId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}kalaka_support_nodes WHERE external_id = %s",
            $externalId
        ));
    }
}
