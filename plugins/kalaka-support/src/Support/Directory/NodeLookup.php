<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

use Kalaka\Foundation\Api\Query\Query;

/**
 * Reads stored nodes by external ID for the import: what a node holds now, so an unchanged row
 * need not run the use case, and the active nodes under some roots, so the import can name the
 * ones its file leaves out. Only IDs the use case would accept are looked up; the column is
 * `ascii_bin`, so the lookup is exact.
 */
final class NodeLookup extends Query
{
    /**
     * @param list<string> $externalIds
     * @return array<string, array{
     *     parent_external_id: ?string,
     *     type: string,
     *     name: string,
     *     aliases: list<string>,
     *     active: bool
     * }> keyed by external ID; IDs with no node are missing
     */
    public function byExternalIds(array $externalIds): array
    {
        $externalIds = self::valid($externalIds);

        return $this->scope(function () use ($externalIds): array {
            $rows = $this->related('nodes', 'kalaka_support_nodes', 'external_id', $externalIds);

            $parentIds = [];
            foreach ($rows as $row) {
                if ($row['parent_id'] !== null) {
                    $parentIds[] = (int) $row['parent_id'];
                }
            }
            $parents = [];
            foreach ($this->related('parents', 'kalaka_support_nodes', 'id', $parentIds) as $parent) {
                $parents[(int) $parent['id']] = (string) $parent['external_id'];
            }

            $nodes = [];
            foreach ($rows as $row) {
                $aliases = $row['aliases'];
                $parentId = $row['parent_id'];
                $nodes[(string) $row['external_id']] = [
                    'parent_external_id' => $parentId === null ? null : ($parents[(int) $parentId] ?? null),
                    'type' => (string) $row['type'],
                    'name' => (string) $row['name'],
                    'aliases' => $aliases === null || $aliases === '' ? [] : explode("\n", $aliases),
                    'active' => $row['active'] === '1',
                ];
            }

            return $nodes;
        });
    }

    /**
     * The active nodes below these roots, at any depth, without the roots themselves; the
     * ancestors in between may be inactive. Ordered by external ID.
     *
     * @param list<string> $rootExternalIds
     * @return list<array{external_id: string, name: string}>
     */
    public function activeBelow(array $rootExternalIds): array
    {
        $rootExternalIds = self::valid($rootExternalIds);
        if ($rootExternalIds === []) {
            return [];
        }

        $table = $this->table('kalaka_support_nodes');
        $rows = $this->scope(fn (): array => $this->select(
            'below',
            'WITH RECURSIVE tree (node_id, depth) AS ('
            . ' SELECT r.id, 0 FROM %i r WHERE r.external_id IN ('
            . implode(', ', array_fill(0, count($rootExternalIds), '%s')) . ')'
            . ' UNION ALL'
            . ' SELECT c.id, t.depth + 1 FROM tree t JOIN %i c ON c.parent_id = t.node_id WHERE t.depth < 32'
            . ') SELECT DISTINCT n.external_id, n.name FROM tree t JOIN %i n ON n.id = t.node_id'
            . ' WHERE t.depth > 0 AND n.active = 1 ORDER BY n.external_id',
            [$table, ...$rootExternalIds, $table, $table]
        ));

        return array_map(
            static fn (array $row): array => [
                'external_id' => (string) $row['external_id'],
                'name' => (string) $row['name'],
            ],
            $rows
        );
    }

    /**
     * @param list<string> $externalIds
     * @return list<string> the distinct IDs the use case's schema accepts
     */
    private static function valid(array $externalIds): array
    {
        return array_values(array_unique(array_filter(
            $externalIds,
            static fn (string $id): bool => strlen($id) <= 255
                && preg_match('/' . Node::EXTERNAL_ID . '/', $id) === 1
        )));
    }
}
