<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\View\ViewList;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Actor\Actor;

/**
 * The list the grid's views route answers (`GET /events/{id}/views`). It is what a counting save needs: the
 * stored view's shape, and, for full access, the `version` a save sends back. It runs outside any use case.
 */
final class ViewsData
{
    public function __construct(
        private readonly Access $access = new Access(),
        private readonly ViewList $views = new ViewList(),
        private readonly GridModel $model = new GridModel(),
    ) {
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @return array{full: bool, views: list<array<string, mixed>>}
     */
    public function of(Actor $actor, int $eventId, array $fields): array
    {
        if (!$this->access->isFull($actor)) {
            $views = [];
            foreach ($this->access->heldViews($actor, $eventId) as $held) {
                $views[] = [
                    'key' => GridModel::tabKey($held),
                    'view' => $held->viewId ?? 0,
                    'name' => $this->model->tabName($held, $fields),
                ];
            }

            return ['full' => false, 'views' => $views];
        }

        $views = [];
        foreach ($this->views->ofEvent($eventId) as $stored) {
            $shape = ViewShape::fromStored($stored['filter'], $stored['columns'], $stored['counts']);
            $views[] = [
                'key' => 'v' . $stored['id'],
                'view' => $stored['id'],
                'name' => $stored['name'],
                // What a save sends back: the stored shape.
                'shape' => [
                    'filter' => json_decode($shape->filterJson(), true),
                    'columns' => $shape->columns,
                    'counts' => (object) $shape->counts,
                ],
            ];
        }

        return ['full' => true, 'views' => $views];
    }
}
