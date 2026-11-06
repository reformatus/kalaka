<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

use Kalaka\Foundation\Api\Query\Query;

/**
 * Looks the tree up in SQL: a node's ancestors and a node's descendants, each one recursive
 * statement whatever the number of nodes asked about. The recursion stops at depth 32, so a cycle
 * written outside the use case cannot loop. foundation.md, "Facts, decisions and computed values".
 */
final class NodeTreeQuery extends Query
{
    /**
     * Every node's chain of ancestors, root first, without the node itself. A root, and an id with
     * no node, have an empty chain. Inactive nodes are included.
     *
     * @param list<int> $ids
     * @return array<int, list<array{id: int, name: string, type: string}>> keyed by the given ids
     */
    public function ancestors(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $chains = array_fill_keys($ids, []);
        if ($ids === []) {
            return $chains;
        }

        $table = $this->table('kalaka_support_nodes');
        $rows = $this->scope(fn (): array => $this->select(
            'ancestors',
            'WITH RECURSIVE chain (origin, ancestor_id, depth) AS ('
            . ' SELECT n.id, n.parent_id, 1 FROM %i n WHERE n.parent_id IS NOT NULL AND n.id IN ('
            . implode(', ', array_fill(0, count($ids), '%d')) . ')'
            . ' UNION ALL'
            . ' SELECT c.origin, a.parent_id, c.depth + 1 FROM chain c JOIN %i a ON a.id = c.ancestor_id'
            . ' WHERE a.parent_id IS NOT NULL AND c.depth < 32'
            . ') SELECT c.origin, a.id, a.name, a.type FROM chain c JOIN %i a ON a.id = c.ancestor_id'
            . ' ORDER BY c.origin, c.depth DESC',
            [$table, ...$ids, $table, $table]
        ));

        foreach ($rows as $row) {
            $chains[(int) $row['origin']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
            ];
        }

        return $chains;
    }

    /**
     * The node and everything below it, active or not; empty when there is no such node.
     *
     * @return list<int>
     */
    public function descendants(int $id): array
    {
        $rows = $this->scope(fn (): array => $this->select(
            'descendants',
            'WITH RECURSIVE tree (node_id, depth) AS ('
            . ' SELECT n.id, 1 FROM %i n WHERE n.id = %d'
            . ' UNION ALL'
            . ' SELECT c.id, t.depth + 1 FROM tree t JOIN %i c ON c.parent_id = t.node_id WHERE t.depth < 32'
            . ') SELECT DISTINCT node_id FROM tree ORDER BY node_id',
            [$this->table('kalaka_support_nodes'), $id, $this->table('kalaka_support_nodes')]
        ));

        return array_map(static fn (array $row): int => (int) $row['node_id'], $rows);
    }
}
