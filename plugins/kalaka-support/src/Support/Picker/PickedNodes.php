<?php

declare(strict_types=1);

namespace Kalaka\Support\Picker;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Support\Directory\NodeList;
use Kalaka\Support\Directory\NodeTreeQuery;

/**
 * The congregation field's reads of the directory: whether a node is an answer now (a live terminal
 * leaf, the same rules as the public list), what a stored answer names, live or not, since a node is
 * never deleted, the path an answer stores, and what the picker shows of a current value.
 */
final class PickedNodes extends Query
{
    /** Whether the node is live (active, under active ancestors) and a terminal leaf. One statement. */
    public function isLiveLeaf(int $id): bool
    {
        $table = $this->table('kalaka_support_nodes');
        $rows = $this->scope(fn (): array => $this->select(
            'node',
            NodeList::LIVE . ' SELECT n.id FROM live l JOIN %i n ON n.id = l.node_id'
            . ' WHERE n.id = %d AND ' . NodeList::LEAF,
            [$table, $table, $table, $id, $table]
        ));

        return $rows !== [];
    }

    /**
     * The node with this id, active or not. One statement.
     *
     * @return ?array{external_id: string, name: string} null when there is no such node
     */
    public function node(int $id): ?array
    {
        $rows = $this->scope(fn (): array => $this->related('node', 'kalaka_support_nodes', 'id', [$id]));
        if ($rows === []) {
            return null;
        }

        return ['external_id' => (string) $rows[0]['external_id'], 'name' => (string) $rows[0]['name']];
    }

    /**
     * The node's path as an answer stores it: each node's external ID and type, root first, ending with
     * the node itself; active or not. Empty when there is no such node. The chain is the tree's own
     * (`NodeTreeQuery::ancestors()`, one recursive statement stopping at depth 32); the node rows it
     * names are read in one more.
     *
     * @return list<array{ext: string, type: string}>
     */
    public function path(int $id): array
    {
        $chain = (new NodeTreeQuery())->ancestors([$id])[$id] ?? [];
        $ids = [...array_column($chain, 'id'), $id];
        $rows = $this->scope(fn (): array => $this->related('path', 'kalaka_support_nodes', 'id', $ids));
        $nodes = [];
        foreach ($rows as $row) {
            $nodes[(int) $row['id']] = ['ext' => (string) $row['external_id'], 'type' => (string) $row['type']];
        }

        $path = [];
        foreach ($ids as $nodeId) {
            if (isset($nodes[$nodeId])) {
                $path[] = $nodes[$nodeId];
            }
        }

        return $path;
    }

    /**
     * What the picker shows of a current value, live or not: the node's id and name, its ancestors' ids
     * and names, root first, and whether it is still an answer (a live terminal leaf; the picker marks it
     * when not, H7). Never its external ID, which the page must not show. Three statements: the node, its
     * chain and the live test.
     *
     * @return ?array{id: int, name: string, ancestors: list<array{id: int, name: string}>, live: bool} null
     *     when there is no such node
     */
    public function shown(int $id): ?array
    {
        $node = $this->node($id);
        if ($node === null) {
            return null;
        }
        $chain = (new NodeTreeQuery())->ancestors([$id])[$id] ?? [];

        return [
            'id' => $id,
            'name' => $node['name'],
            'ancestors' => array_map(
                static fn (array $ancestor): array => ['id' => $ancestor['id'], 'name' => $ancestor['name']],
                $chain
            ),
            'live' => $this->isLiveLeaf($id),
        ];
    }
}
