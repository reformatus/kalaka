<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Creates the node with this external ID or updates the columns that changed. The one way nodes
 * change; a node is never deleted. Returns its id and what happened.
 */
final class SaveDirectoryNode implements AdminUseCase
{
    public function __construct(
        private readonly NodeRepository $repository = new NodeRepository(),
        private readonly NodeTreeQuery $tree = new NodeTreeQuery(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/save-directory-node';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'external_id' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'pattern' => Node::EXTERNAL_ID,
                ],
                'parent_external_id' => [
                    'type' => ['string', 'null'],
                    'minLength' => 1,
                    'maxLength' => 255,
                    'pattern' => Node::EXTERNAL_ID,
                ],
                'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'aliases' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '\A[^\r\n]*\z'],
                ],
                'active' => ['type' => 'boolean'],
            ],
            'required' => ['external_id', 'parent_external_id', 'type', 'name', 'aliases', 'active'],
            'additionalProperties' => false,
        ];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    /** @return array{id: int, outcome: 'created'|'updated'|'unchanged'} */
    public function execute(array $input, Context $context): array
    {
        $node = $this->repository->findByExternalId((string) $input['external_id'], $context);
        $parentId = $this->parentIdOf($input, $node, $context);

        $aliases = [];
        foreach (is_array($input['aliases']) ? $input['aliases'] : [] as $alias) {
            $aliases[] = (string) $alias;
        }

        if ($node === null) {
            $stored = $this->repository->upsert(
                new Node(
                    (string) $input['external_id'],
                    $parentId,
                    (string) $input['type'],
                    (string) $input['name'],
                    $aliases,
                    (bool) $input['active'],
                ),
                $context
            );

            return ['id' => $stored->id, 'outcome' => 'created'];
        }

        $context->touch(new Subject('support_node', null, (string) $node->id));
        $node->parentId = $parentId;
        $node->type = (string) $input['type'];
        $node->name = (string) $input['name'];
        $node->aliases = $aliases;
        $node->active = (bool) $input['active'];
        $stored = $this->repository->upsert($node, $context);

        return ['id' => $stored->id, 'outcome' => $stored->version === $node->version ? 'unchanged' : 'updated'];
    }

    /**
     * The id of the parent the input names, null for a root. The parent is read FOR UPDATE, so two
     * concurrent moves in opposite directions serialize and the cycle check sees the other's move;
     * a deadlock between them aborts one, which is retried or fails.
     *
     * @param array<string, mixed> $input
     * @throws RuleViolation when the parent is unknown, or is the node or one below it
     */
    private function parentIdOf(array $input, ?Node $node, Context $context): ?int
    {
        if ($input['parent_external_id'] === null) {
            return null;
        }

        $parent = $this->repository->findByExternalId((string) $input['parent_external_id'], $context);
        if ($parent === null) {
            throw new RuleViolation('A megadott felettes szervezet nem szerepel a címtárban.');
        }
        if (
            $node !== null
            && $parent->id !== $node->parentId
            && in_array($parent->id, $this->tree->descendants($node->id), true)
        ) {
            throw new RuleViolation('Egy szervezet nem kerülhet önmaga vagy egy alá tartozó szervezet alá.');
        }

        return $parent->id;
    }
}
