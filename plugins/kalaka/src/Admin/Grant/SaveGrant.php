<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Admin\View\ViewRepository;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Stores a new grant (a user's access to one event through a view, with the actions it carries), or
 * replaces the user, view, bound value and actions of a stored one (with its `id` and current
 * `version`). A site-level use case: only full access. Returns the grant's id.
 */
final class SaveGrant implements AdminUseCase
{
    public function __construct(
        private readonly GrantRepository $repository = new GrantRepository(),
        private readonly ViewRepository $views = new ViewRepository(),
        private readonly Events $events = new Events(),
        private readonly GrantQuery $query = new GrantQuery(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/save-grant';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'event_id' => ['type' => 'integer', 'minimum' => 1],
                'user_id' => ['type' => 'integer', 'minimum' => 1],
                'view_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'bound_value' => ['type' => ['string', 'null']],
                'actions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['event_id', 'user_id', 'actions'],
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
        $eventId = (int) $input['event_id'];
        $userId = (int) $input['user_id'];
        $viewId = isset($input['view_id']) ? (int) $input['view_id'] : null;
        $bound = isset($input['bound_value']) ? (string) $input['bound_value'] : null;
        $actions = self::actions($input['actions']);
        if ($bound !== null) {
            $length = mb_strlen($bound, 'UTF-8');
            if ($length < 1 || $length > 191 || !mb_check_encoding($bound, 'UTF-8')) {
                throw new InvalidInput();
            }
        }

        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }
        if (!$this->query->userExists($userId)) {
            throw new InvalidInput();
        }
        $parameter = false;
        if ($viewId !== null) {
            $view = $this->views->find($viewId);
            if ($view === null || $view->eventId !== $eventId) {
                throw new InvalidInput();
            }
            $parameter = $view->shape->hasParameter();
        }
        if ($parameter !== ($bound !== null)) {
            throw new InvalidInput();
        }

        if (!isset($input['id'])) {
            return $this->repository->upsert(new Grant($eventId, $userId, $viewId, $bound, $actions), $context)->id;
        }

        $grant = $this->repository->findForUpdate((int) $input['id'], $context);
        if ($grant === null || $grant->eventId !== $eventId) {
            throw new NotFound();
        }
        $grant->userId = $userId;
        $grant->viewId = $viewId;
        $grant->boundValue = $bound;
        $grant->actions = $actions;
        $grant->version = (int) $input['version'];

        return $this->repository->upsert($grant, $context)->id;
    }

    /**
     * The catalogue names in byte order, each once.
     *
     * @return list<string>
     * @throws InvalidInput when a name is not in the catalogue
     */
    private static function actions(mixed $actions): array
    {
        $catalogue = ActionCatalogue::current();
        $names = [];
        foreach (is_array($actions) ? $actions : [] as $action) {
            if (!is_string($action) || !$catalogue->has($action)) {
                throw new InvalidInput();
            }
            $names[$action] = $action;
        }
        $names = array_values($names);
        sort($names, SORT_STRING);

        return $names;
    }
}
