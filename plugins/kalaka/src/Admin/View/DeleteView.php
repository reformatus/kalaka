<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Admin\Grant\DeleteGrant;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Deletes a view (with its `id` and current `version`) and the grants that use it, each through a nested
 * `kalaka/delete-grant` with its own audit entry, in the same run (S36-4, owner ruling 2026-11-16). A
 * site-level use case: only full access.
 */
final class DeleteView implements AdminUseCase
{
    public function __construct(
        private readonly ViewRepository $repository = new ViewRepository(),
        private readonly GrantQuery $grants = new GrantQuery(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/delete-view';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['id', 'version'],
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
        $view = $this->repository->findForUpdate((int) $input['id'], $context) ?? throw new NotFound();
        // S36-4 (owner ruling 2026-11-16, (c)): the view's grants go with it, each through a nested
        // `kalaka/delete-grant`, so each has its own audit entry under this one.
        foreach ($this->grants->usingView($view->eventId, $view->id) as $grantId) {
            $context->run(new DeleteGrant(), ['id' => $grantId]);
        }
        $view->version = (int) $input['version'];
        $this->repository->delete($view, $context);

        return null;
    }
}
