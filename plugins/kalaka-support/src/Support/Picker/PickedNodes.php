<?php

declare(strict_types=1);

namespace Kalaka\Support\Picker;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Support\Directory\NodeList;

/**
 * The congregation field's reads of the directory: whether a node is an answer now (a live terminal
 * leaf, the same rules as the public list), and what a stored answer names, live or not, since a
 * node is never deleted. One statement each.
 */
final class PickedNodes extends Query
{
    /** Whether the node is live (active, under active ancestors) and a terminal leaf. */
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
     * The node with this id, active or not.
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
}
