<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grant;

use Kalaka\Admin\View\ViewRepository;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\VersionConflict;
use Kalaka\Foundation\Api\Module\ActionCatalogue;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Stores a new grant (a user's access to one event through a view, with the actions it carries), or
 * replaces the user, view, bound value and actions of a stored one (with its `id` and current
 * `version`). A site-level use case: only full access. A user holds one grant per event, view and bound value
 * (a blank bound value is none): a save that repeats another grant's triple is refused as `DuplicateGrant`.
 * Returns the grant's id.
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
        // A blank bound value is none.
        $bound = isset($input['bound_value']) ? trim((string) $input['bound_value']) : null;
        if ($bound === '') {
            $bound = null;
        }
        $actions = self::actions($input['actions']);
        if ($bound !== null) {
            $length = mb_strlen($bound, 'UTF-8');
            if ($length > 191 || !mb_check_encoding($bound, 'UTF-8')) {
                throw new InvalidInput();
            }
        }

        // The event row is the run's first lock: the duplicate check below and the write are one step per event.
        $this->events->lock($context, $eventId);
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
            $this->refuseDuplicate($eventId, $userId, $viewId, $bound, 0);

            return $this->repository->upsert(new Grant($eventId, $userId, $viewId, $bound, $actions), $context)->id;
        }

        $grant = $this->repository->findForUpdate((int) $input['id'], $context);
        if ($grant === null || $grant->eventId !== $eventId) {
            throw new NotFound();
        }
        // A stale edit is a version conflict before it is anything else.
        if ($grant->version !== (int) $input['version']) {
            $error = new VersionConflict();
            throw $error;
        }
        $this->refuseDuplicate($eventId, $userId, $viewId, $bound, $grant->id);
        $grant->userId = $userId;
        $grant->viewId = $viewId;
        $grant->boundValue = $bound;
        $grant->actions = $actions;
        $grant->version = (int) $input['version'];

        return $this->repository->upsert($grant, $context)->id;
    }

    /** @throws DuplicateGrant when another grant of the event has the same user, view and bound value */
    private function refuseDuplicate(int $eventId, int $userId, ?int $viewId, ?string $bound, int $exceptId): void
    {
        if ($this->query->holdsSame($eventId, $userId, $viewId, $bound, $exceptId)) {
            throw new DuplicateGrant();
        }
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
