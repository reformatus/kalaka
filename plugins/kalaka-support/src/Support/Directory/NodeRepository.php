<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_support_nodes`. There is no delete: a node is deactivated, never removed.
 *
 * @extends EntityRepository<int, Node>
 */
final class NodeRepository extends EntityRepository
{
    /** The node with exactly this external ID (the column is `ascii_bin`), or null. */
    public function findByExternalId(string $externalId, ?Context $forUpdate = null): ?Node
    {
        return $this->findWhere('external_id = %s', [$externalId], $forUpdate)[0] ?? null;
    }

    protected function table(): string
    {
        return 'kalaka_support_nodes';
    }

    protected function keyColumns(): array
    {
        return ['id'];
    }

    protected function autoIncrement(): bool
    {
        return true;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'external_id' => $entity->externalId,
            'parent_id' => $entity->parentId,
            'type' => $entity->type,
            'name' => $entity->name,
            'aliases' => $entity->aliases === [] ? null : implode("\n", $entity->aliases),
            'active' => $entity->active ? 1 : 0,
            'version' => $entity->version,
        ];
    }

    protected function fromRow(array $row): object
    {
        $aliases = $row['aliases'] ?? null;

        return new Node(
            (string) $row['external_id'],
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            (string) $row['type'],
            (string) $row['name'],
            $aliases === null || $aliases === '' ? [] : explode("\n", $aliases),
            $row['active'] === '1',
            (int) $row['id'],
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return new Subject('support_node', null, (string) $record->id);
    }
}
