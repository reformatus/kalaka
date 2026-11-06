<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Support\Directory;

use Kalaka\Support\Directory\NodeTreeQuery;

require_once __DIR__ . '/SupportTestCase.php';

/** AC12 and AC13: ancestors and descendants, each in one statement. */
final class NodeTreeQueryTest extends SupportTestCase
{
    /** @var array<string, int> */
    private array $ids = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->ids = $this->buildTree();
    }

    public function testACongregationsAncestorsAreItsRootDistrictAndDeaneryRootFirst(): void
    {
        $chains = (new NodeTreeQuery())->ancestors([$this->ids['D1-A-1']]);

        self::assertSame(
            [$this->ids['D1-A-1'] => [
                ['id' => $this->ids['ROOT'], 'name' => 'Teszt Egyház', 'type' => 'Felekezet'],
                ['id' => $this->ids['D1'], 'name' => 'Északi Teszt Egyházkerület', 'type' => 'Egyházkerület'],
                ['id' => $this->ids['D1-A'], 'name' => 'Budai Teszt Egyházmegye', 'type' => 'Egyházmegye'],
            ]],
            $chains
        );
    }

    public function testARootHasNoAncestorsAndAnUnknownIdHasNone(): void
    {
        self::assertSame(
            [$this->ids['ROOT'] => [], 999999 => []],
            (new NodeTreeQuery())->ancestors([$this->ids['ROOT'], 999999])
        );
    }

    public function testSeveralCongregationsGetTheirOwnChainsInOneStatement(): void
    {
        $asked = [$this->ids['D1-A-1'], $this->ids['D2-B-3'], $this->ids['D1-B-2']];
        $chains = [];

        $statements = $this->statementsDuring(function () use ($asked, &$chains): void {
            $chains = (new NodeTreeQuery())->ancestors($asked);
        });

        self::assertCount(1, $statements);
        self::assertSame($asked, array_keys($chains));
        self::assertSame(
            [$this->ids['ROOT'], $this->ids['D1'], $this->ids['D1-A']],
            array_column($chains[$this->ids['D1-A-1']], 'id')
        );
        self::assertSame(
            [$this->ids['ROOT'], $this->ids['D2'], $this->ids['D2-B']],
            array_column($chains[$this->ids['D2-B-3']], 'id')
        );
        self::assertSame(
            [$this->ids['ROOT'], $this->ids['D1'], $this->ids['D1-B']],
            array_column($chains[$this->ids['D1-B-2']], 'id')
        );
    }

    public function testAnInactiveDeaneryIsStillInItsCongregationsChains(): void
    {
        $this->save([
            'external_id' => 'D1-A',
            'parent_external_id' => 'D1',
            'type' => 'Egyházmegye',
            'name' => 'Budai Teszt Egyházmegye',
            'active' => false,
        ]);

        $chains = (new NodeTreeQuery())->ancestors([$this->ids['D1-A-1']]);

        self::assertContains($this->ids['D1-A'], array_column($chains[$this->ids['D1-A-1']], 'id'));
    }

    public function testNoIdsRunNoStatement(): void
    {
        $statements = $this->statementsDuring(static function (): void {
            (new NodeTreeQuery())->ancestors([]);
        });

        self::assertSame([], $statements);
    }

    public function testADistrictsDescendantsAreItselfItsDeaneriesAndTheirCongregationsActiveOrNot(): void
    {
        $this->save([
            'external_id' => 'D1-A-2',
            'parent_external_id' => 'D1-A',
            'type' => 'Gyülekezet',
            'name' => 'Ottó Teszt',
            'active' => false,
        ]);
        $ids = [];

        $statements = $this->statementsDuring(function () use (&$ids): void {
            $ids = (new NodeTreeQuery())->descendants($this->ids['D1']);
        });

        self::assertCount(1, $statements);
        $expected = array_map(
            fn (string $key): int => $this->ids[$key],
            ['D1', 'D1-A', 'D1-B', 'D1-A-1', 'D1-A-2', 'D1-A-3', 'D1-B-1', 'D1-B-2', 'D1-B-3']
        );
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertCount(9, $ids);
    }

    public function testACongregationsDescendantsAreItself(): void
    {
        self::assertSame([$this->ids['D1-A-1']], (new NodeTreeQuery())->descendants($this->ids['D1-A-1']));
        self::assertSame([], (new NodeTreeQuery())->descendants(999999));
    }

    public function testACycleWrittenOutsideTheUseCaseDoesNotLoop(): void
    {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'kalaka_support_nodes',
            ['parent_id' => $this->ids['D1-A-1']],
            ['id' => $this->ids['ROOT']]
        );

        $descendants = (new NodeTreeQuery())->descendants($this->ids['ROOT']);
        $chains = (new NodeTreeQuery())->ancestors([$this->ids['D1-A-1']]);

        self::assertContains($this->ids['D1-A-1'], $descendants);
        self::assertLessThanOrEqual(32, count($chains[$this->ids['D1-A-1']]));
    }
}
