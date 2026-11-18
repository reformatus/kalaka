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
use Kalaka\ParticipantFields\Api\ParticipantFields;

/**
 * Stores a new view of an event, or replaces the name, filter, columns and counts of a stored one (with its
 * `id` and current `version`). A site-level use case: only full access. Returns the view's id.
 *
 * Removing the parameter term or moving it to another column takes the bound value from every grant of
 * the view, each audited; those holders then see the whole view (S36-4, owner ruling 2026-11-16).
 *
 * `counts` (S37), the members the grid's sum row counts per column, must be members of their column: a
 * status, or an option of the participant field. With a `counts` key (even an empty one) the save replaces
 * the stored counts; without one, a stored view keeps its counts for the columns it still lists. The
 * options are read before the run (`prepared()`), as `ParticipantFields::of()` reads outside a transaction;
 * a `SaveView` made without them refuses counts on a field column.
 */
final class SaveView implements AdminUseCase
{
    /** @param ?array<string, list<string>> $options the option values by field key; null: none read */
    public function __construct(
        private readonly ViewRepository $repository = new ViewRepository(),
        private readonly Events $events = new Events(),
        private readonly GrantQuery $grants = new GrantQuery(),
        private readonly GrantRepository $grantRepository = new GrantRepository(),
        private readonly ?array $options = null,
    ) {
    }

    /**
     * The use case for this input, with the options of the event's participant fields read now, outside any
     * run, for the check of `counts`.
     *
     * @param array<string, mixed> $input
     */
    public static function prepared(array $input): self
    {
        $options = [];
        $eventId = is_int($input['event_id'] ?? null) ? $input['event_id'] : 0;
        if (!empty($input['counts']) && $eventId > 0) {
            foreach ((new ParticipantFields())->of($eventId, true) as $field) {
                $options[$field->key] = array_map(
                    static fn (array $option): string => $option['value'],
                    $field->options
                );
            }
        }

        return new self(options: $options);
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
                'counts' => ['type' => 'object'],
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
        $shape = ViewShape::of($input['filter'], $input['columns'], $input['counts'] ?? null);
        $this->checkCounts($shape);
        $eventId = (int) $input['event_id'];
        if ($this->events->find($eventId) === null) {
            throw new NotFound();
        }

        if (!isset($input['id'])) {
            return $this->repository->upsert(new View($eventId, $name, $shape), $context)->id;
        }

        $view = $this->repository->findForUpdate((int) $input['id'], $context);
        if ($view === null || $view->eventId !== $eventId || $view->master) {
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

        if (!array_key_exists('counts', $input)) {
            // Counts left out are kept: the stored ones, for the columns the view still lists.
            $listed = array_column($shape->columns, 'column');
            foreach ($view->shape->counts as $column => $members) {
                if (in_array($column, $listed, true)) {
                    $shape = $shape->withCounts($column, $members);
                }
            }
        }

        $view->name = $name;
        $view->shape = $shape;
        $view->version = (int) $input['version'];

        return $this->repository->upsert($view, $context)->id;
    }

    /**
     * Every counted member of a field column must be one of the field's options, retired ones included.
     *
     * @throws InvalidInput
     */
    private function checkCounts(ViewShape $shape): void
    {
        foreach ($shape->counts as $column => $members) {
            if (!str_starts_with($column, 'field:')) {
                continue;
            }
            $options = $this->options[substr($column, 6)] ?? [];
            if ($options === [] || array_diff($members, $options) !== []) {
                throw new InvalidInput();
            }
        }
    }
}
