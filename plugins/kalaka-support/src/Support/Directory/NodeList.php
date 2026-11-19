<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

use Kalaka\Foundation\Api\Query\Query;

/**
 * The public list of live nodes: active nodes under active ancestors (a root is live when it is
 * active). Searches by name and alias, accent-blind, in the whole directory or below one node, or lists the
 * children of one node. Each item
 * says whether it is a terminal leaf, a node with no children at all, active or not: only a leaf
 * is an answer of the congregation field (owner ruling S28-1). Three statements: the count, the
 * page and the page's ancestors; the last only for a non-empty page.
 */
final class NodeList extends Query
{
    // a node is live when it and every ancestor are active; only live nodes are listed
    /** The live set: a recursive walk from the active roots down through active children. */
    public const LIVE = 'WITH RECURSIVE live (node_id, depth) AS ('
        . ' SELECT r.id, 1 FROM %i r WHERE r.parent_id IS NULL AND r.active = 1'
        . ' UNION ALL'
        . ' SELECT c.id, l.depth + 1 FROM live l JOIN %i c ON c.parent_id = l.node_id'
        . ' WHERE c.active = 1 AND l.depth < 32'
        . ')';

    /**
     * Whether node `n` is a terminal leaf: no node names it as its parent,
     * active or not. The one leaf rule, for the route's flag and the congregation field's check
     * alike; the `%i` is the node table.
     */
    public const LEAF = 'NOT EXISTS (SELECT 1 FROM %i c WHERE c.parent_id = n.id)';

    /**
     * The subtree below one node (`%d`), without the node itself, active or not, as a second walk beside the
     * live set; it stops at depth 32 as the other walks do.
     */
    private const BELOW = 'below (node_id, depth) AS ('
        . ' SELECT c.id, 1 FROM %i c WHERE c.parent_id = %d'
        . ' UNION ALL'
        . ' SELECT c.id, b.depth + 1 FROM below b JOIN %i c ON c.parent_id = b.node_id WHERE b.depth < 32'
        . ')';

    /** One search word, in the accent-blind collation (engineering.md, "Database"). */
    private const SEARCH_WORD = '(n.name COLLATE utf8mb4_unicode_520_ci LIKE %s'
        . ' OR n.aliases COLLATE utf8mb4_unicode_520_ci LIKE %s)';

    public function __construct(private readonly NodeTreeQuery $tree = new NodeTreeQuery())
    {
        parent::__construct();
    }

    /**
     * @param ?string $search trimmed, every word must occur in the name or an alias
     * @param ?int $parent 0 for the roots, a node's id for its children, null for any node
     * @param ?int $within a node's id: only the nodes below it, at any depth, never
     *     the node itself (the picker's search inside an opened node, H2); null for the whole directory
     * @return array{
     *     items: list<array{
     *         id: int,
     *         name: string,
     *         type: string,
     *         parent: int,
     *         leaf: bool,
     *         ancestors: list<array{id: int, name: string, type: string}>
     *     }>,
     *     total: int
     * }
     */
    public function live(?string $search, ?int $parent, int $page, int $perPage, ?int $within = null): array
    {
        return $this->scope(function () use ($search, $parent, $page, $perPage, $within): array {
            $words = $search === null ? [] : self::words($search);

            $conditions = [];
            $args = [];
            if ($parent !== null) {
                $conditions[] = $parent === 0 ? 'n.parent_id IS NULL' : 'n.parent_id = %d';
                if ($parent !== 0) {
                    $args[] = $parent;
                }
            }
            $table = $this->table('kalaka_support_nodes');
            $with = self::LIVE;
            $withArgs = [$table, $table];
            if ($within !== null) {
                // the subtree below the node, active or not (the live set filters it), walked in the same
                // statement as the live set
                $with .= ', ' . self::BELOW;
                array_push($withArgs, $table, $within, $table);
                $conditions[] = 'n.id IN (SELECT b.node_id FROM below b)';
            }
            foreach ($words as $word) {
                $like = '%' . $this->escapeLike($word) . '%';
                $conditions[] = self::SEARCH_WORD;
                array_push($args, $like, $like);
            }
            $where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);

            $count = $this->select(
                'count',
                $with . ' SELECT COUNT(*) AS total FROM live l JOIN %i n ON n.id = l.node_id WHERE ' . $where,
                [...$withArgs, $table, ...$args]
            );
            $total = (int) ($count[0]['total'] ?? 0);

            $rows = $this->select(
                'node',
                $with . ' SELECT n.id, n.name, n.type, n.parent_id, ' . self::LEAF . ' AS leaf'
                . ' FROM live l JOIN %i n ON n.id = l.node_id'
                . ' WHERE ' . $where . ' ORDER BY n.name, n.id LIMIT %d OFFSET %d',
                [
                    // the walks', then the leaf test's and the main statement's: one table
                    ...$withArgs,
                    $table,
                    $table,
                    ...$args,
                    $perPage,
                    ($page - 1) * $perPage,
                ]
            );

            $chains = $this->tree->ancestors(array_map(static fn (array $row): int => (int) $row['id'], $rows));
            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'type' => (string) $row['type'],
                    'parent' => (int) $row['parent_id'],
                    'leaf' => (string) $row['leaf'] === '1',
                    'ancestors' => $chains[(int) $row['id']] ?? [],
                ];
            }

            return ['items' => $items, 'total' => $total];
        });
    }

    /**
     * The search split into words at any Unicode whitespace. The route uses it too, so a search
     * that yields no words counts as no search there and here alike.
     *
     * @return list<string>
     */
    public static function words(string $search): array
    {
        return preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function escapeLike(string $word): string
    {
        global $wpdb;

        return $wpdb->esc_like($word);
    }
}
