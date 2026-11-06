<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

use Kalaka\Foundation\Api\Query\Query;

/**
 * The public list of live nodes: active nodes under active ancestors (a root is live when it is
 * active). Searches by name and alias, accent-blind, or lists the children of one node. Three
 * statements: the count, the page and the page's ancestors; the last only for a non-empty page.
 */
final class NodeList extends Query
{
    // a node is live when it and every ancestor are active; only live nodes are listed
    /** The live set: a recursive walk from the active roots down through active children. */
    private const LIVE = 'WITH RECURSIVE live (node_id, depth) AS ('
        . ' SELECT r.id, 1 FROM %i r WHERE r.parent_id IS NULL AND r.active = 1'
        . ' UNION ALL'
        . ' SELECT c.id, l.depth + 1 FROM live l JOIN %i c ON c.parent_id = l.node_id'
        . ' WHERE c.active = 1 AND l.depth < 32'
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
     * @return array{
     *     items: list<array{
     *         id: int,
     *         name: string,
     *         type: string,
     *         parent: int,
     *         ancestors: list<array{id: int, name: string, type: string}>
     *     }>,
     *     total: int
     * }
     */
    public function live(?string $search, ?int $parent, int $page, int $perPage): array
    {
        return $this->scope(function () use ($search, $parent, $page, $perPage): array {
            $words = $search === null ? [] : self::words($search);

            $conditions = [];
            $args = [];
            if ($parent !== null) {
                $conditions[] = $parent === 0 ? 'n.parent_id IS NULL' : 'n.parent_id = %d';
                if ($parent !== 0) {
                    $args[] = $parent;
                }
            }
            foreach ($words as $word) {
                $like = '%' . $this->escapeLike($word) . '%';
                $conditions[] = self::SEARCH_WORD;
                array_push($args, $like, $like);
            }
            $where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);

            $count = $this->select(
                'count',
                self::LIVE . ' SELECT COUNT(*) AS total FROM live l JOIN %i n ON n.id = l.node_id WHERE ' . $where,
                [...$this->tables(), ...$args]
            );
            $total = (int) ($count[0]['total'] ?? 0);

            $rows = $this->select(
                'node',
                self::LIVE . ' SELECT n.id, n.name, n.type, n.parent_id FROM live l JOIN %i n ON n.id = l.node_id'
                . ' WHERE ' . $where . ' ORDER BY n.name, n.id LIMIT %d OFFSET %d',
                [
                    ...$this->tables(),
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

    /** @return list<string> the node table for each `%i` in the live set and in the main statement */
    private function tables(): array
    {
        $table = $this->table('kalaka_support_nodes');

        return [$table, $table, $table];
    }

    private function escapeLike(string $word): string
    {
        global $wpdb;

        return $wpdb->esc_like($word);
    }
}
