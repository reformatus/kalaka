<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Admin\Grant\GrantRepository;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Stores a new view of an event, or replaces the name, filter and columns of a stored one (with its
 * `id` and current `version`). A site-level use case: only full access. Returns the view's id.
 *
 * Removing the parameter term or moving it to another column takes the bound value from every grant of
 * the view, each audited; those holders then see the whole view (S36-4, owner ruling 2026-11-16).
 */
final class SaveView implements AdminUseCase
{
    public function __construct(
        private readonly ViewRepository $repository = new ViewRepository(),
        private readonly Events $events = new Events(),
        private readonly GrantQuery $grants = new GrantQuery(),
        private readonly GrantRepository $grantRepository = new GrantRepository(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/save-view';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string'],
                'filter' => ['type' => 'array'],
                'columns' => ['type' => 'array'],
            ],
            'required' => ['event_id', 'name', 'filter', 'columns'],
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

    public function execute(array $input, Context $context): int
    {
        if (isset($input['id']) !== isset($input['version'])) {
            throw new InvalidInput();
        }
        $name = trim((string) $input['name']);
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 1 || $length > 191) {
            throw new InvalidInput();
        }
        $shape = ViewShape::of($input['filter'], $input['columns']);
        $eventId = (int) $input['event_id'];
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }

        if (!isset($input['id'])) {
            return $this->repository->upsert(new View($eventId, $name, $shape), $context)->id;
        }

        $view = $this->repository->findForUpdate((int) $input['id'], $context);
        if ($view === null || $view->eventId !== $eventId) {
            throw new NotFound();
        }

        // S36-4 (owner ruling 2026-11-16, (c)): when the parameter term is removed or moved to another column,
        // the values bound to it lose their meaning; each grant loses its bound value (audited) and its
        // holder sees the whole view.
        $parameter = $view->shape->parameterColumn();
        if ($parameter !== null && $parameter !== $shape->parameterColumn()) {
            foreach ($this->grants->boundToView($eventId, $view->id) as $grantId) {
                $grant = $this->grantRepository->findForUpdate($grantId, $context);
                if ($grant !== null) {
                    $grant->boundValue = null;
                    $this->grantRepository->upsert($grant, $context);
                }
            }
        }

        $view->name = $name;
        $view->shape = $shape;
        $view->version = (int) $input['version'];

        return $this->repository->upsert($view, $context)->id;
    }
}
