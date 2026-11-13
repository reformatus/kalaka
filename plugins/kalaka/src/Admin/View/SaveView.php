<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Stores a new view of an event, or replaces the name, filter and columns of a stored one (with its
 * `id` and current `version`). A site-level use case: only full access. Returns the view's id.
 *
 * An edit never widens access silently: while a grant binds a value to the view's parameter, the
 * parameter term may not be removed or moved to another column.
 */
final class SaveView implements AdminUseCase
{
    public function __construct(
        private readonly ViewRepository $repository = new ViewRepository(),
        private readonly Events $events = new Events(),
        private readonly GrantQuery $grants = new GrantQuery(),
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

        // [UNAPPROVED S36-4] Removing or moving the parameter term would widen every bound grant.
        $parameter = $view->shape->parameterColumn();
        if (
            $parameter !== null
            && $parameter !== $shape->parameterColumn()
            && $this->grants->boundToView($eventId, $view->id)
        ) {
            throw new RuleViolation();
        }

        $view->name = $name;
        $view->shape = $shape;
        $view->version = (int) $input['version'];

        return $this->repository->upsert($view, $context)->id;
    }
}
