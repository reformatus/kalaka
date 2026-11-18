<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Deletes a grant: it is access, not a registration fact, and the audit keeps who held what. A
 * site-level use case: only full access.
 */
final class DeleteGrant implements AdminUseCase
{
    public function __construct(private readonly GrantRepository $repository = new GrantRepository())
    {
    }

    public static function name(): string
    {
        return 'kalaka/delete-grant';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['id'],
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

    public function execute(array $input, Context $context): null
    {
        $grant = $this->repository->findForUpdate((int) $input['id'], $context);
        if ($grant === null) {
            throw new NotFound();
        }
        $this->repository->delete($grant, $context);

        return null;
    }
}
