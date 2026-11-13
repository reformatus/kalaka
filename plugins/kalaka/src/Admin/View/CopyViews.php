<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

use Kalaka\Admin\Grant\Grant;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Admin\Grant\GrantRepository;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Copies every view of one event to another, optionally with their grants (foundation.md, "Views and
 * grants": "copied to the next event, optionally with their grants"). Grants on the master view are
 * copied with the grants too. Meant to run nested, from the use case that duplicates an event; a
 * site-level use case: only full access. Returns the new view ids by the old ones.
 *
 * [UNAPPROVED S36-8]
 */
final class CopyViews implements AdminUseCase
{
    public function __construct(
        private readonly ViewRepository $views = new ViewRepository(),
        private readonly GrantRepository $grantRepository = new GrantRepository(),
        private readonly GrantQuery $grants = new GrantQuery(),
        private readonly ViewList $list = new ViewList(),
        private readonly Events $events = new Events(),
    ) {
    }

    public static function name(): string
    {
        return 'kalaka/copy-views';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from_event_id' => ['type' => 'integer', 'minimum' => 1],
                'to_event_id' => ['type' => 'integer', 'minimum' => 1],
                'with_grants' => ['type' => 'boolean', 'default' => false],
            ],
            'required' => ['from_event_id', 'to_event_id'],
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

    /** @return array<int, int> */
    public function execute(array $input, Context $context): array
    {
        $from = (int) $input['from_event_id'];
        $to = (int) $input['to_event_id'];
        if ($from === $to) {
            throw new InvalidInput();
        }
        if ($this->events->find($from) === null || $this->events->find($to) === null) {
            throw new NotFound();
        }

        $copied = [];
        foreach ($this->list->ofEvent($from) as $view) {
            $shape = ViewShape::fromStored($view['filter'], $view['columns']);
            $copied[$view['id']] = $this->views->upsert(new View($to, $view['name'], $shape), $context)->id;
        }

        if (!empty($input['with_grants'])) {
            foreach ($this->grants->ofEvent($from) as $grant) {
                $viewId = $grant['view_id'] === null ? null : ($copied[$grant['view_id']] ?? null);
                if ($grant['view_id'] !== null && $viewId === null) {
                    continue; // The grant's view is gone: it held nothing, and the copy would hold nothing.
                }
                $this->grantRepository->upsert(
                    new Grant($to, $grant['user_id'], $viewId, $grant['bound_value'], $grant['actions']),
                    $context
                );
            }
        }

        return $copied;
    }
}
