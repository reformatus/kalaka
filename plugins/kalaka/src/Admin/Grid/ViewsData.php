<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

use Kalaka\Admin\Api\Access;
use Kalaka\Admin\Api\HeldView;
use Kalaka\Admin\Api\ViewQuery;
use Kalaka\Admin\Grant\GrantQuery;
use Kalaka\Admin\View\ViewList;
use Kalaka\Admin\View\ViewShape;
use Kalaka\Foundation\Api\Actor\Actor;

/**
 * What the „Nézetek” dialog lists (S37). Full access: every view of the event with its condition, its
 * columns, its grants (the holder, the bound value and that grant's count) and its count (none for a view
 * with a parameter, whose count depends on the grant). A grant holder: only their own views, with their
 * count and columns, and nothing event-wide (S36-9). It reads WordPress users and the event's fields, so it
 * runs outside any use case.
 */
final class ViewsData
{
    public function __construct(
        private readonly Access $access = new Access(),
        private readonly ViewQuery $query = new ViewQuery(),
        private readonly ViewList $views = new ViewList(),
        private readonly GrantQuery $grants = new GrantQuery(),
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
                    'count' => $this->query->count($held),
                    'columns' => $this->labels($held, $fields),
                ];
            }

            return ['full' => false, 'views' => $views];
        }

        $grantsByView = [];
        foreach ($this->grants->ofEvent($eventId) as $grant) {
            if ($grant['view_id'] !== null) {
                $grantsByView[$grant['view_id']][] = $grant;
            }
        }

        $views = [];
        foreach ($this->views->ofEvent($eventId) as $stored) {
            $held = $this->access->fullView($actor, $eventId, $stored['id']);
            $shape = ViewShape::fromStored($stored['filter'], $stored['columns'], $stored['counts']);
            $grants = [];
            foreach ($grantsByView[$stored['id']] ?? [] as $grant) {
                $grantView = $this->access->grantView($actor, $eventId, $grant['id']);
                $user = get_userdata($grant['user_id']);
                $grants[] = [
                    'grant' => $grant['id'],
                    'user_id' => $grant['user_id'],
                    'user' => $user instanceof \WP_User ? $user->display_name : '#' . $grant['user_id'],
                    'bound' => $grant['bound_value'] === null ? null : $this->valueLabel(
                        (string) $shape->parameterColumn(),
                        $grant['bound_value'],
                        $fields,
                        $eventId
                    ),
                    'count' => $grantView === null ? null : $this->query->count($grantView),
                ];
            }
            $parameter = $shape->parameterColumn();
            $views[] = [
                'key' => GridModel::tabKey($held),
                'view' => $stored['id'],
                'name' => $stored['name'],
                'count' => $parameter === null ? $this->query->count($held) : null,
                'terms' => count($shape->filter),
                'filterText' => $this->filterText($shape, $fields, $eventId),
                'param' => $parameter === null ? null : $this->label($parameter, $fields, $eventId),
                'columns' => $this->labels($held, $fields),
                'grants' => $grants,
                // What a save sends back: the stored shape, and the tab's columns once it changes.
                'shape' => [
                    'filter' => json_decode($shape->filterJson(), true),
                    'columns' => $shape->columns,
                    'counts' => (object) $shape->counts,
                ],
                'tab' => $this->model->columns($held, $fields),
            ];
        }

        return ['full' => true, 'views' => $views];
    }

    /**
     * @param array<string, FieldDefinition> $fields
     * @return list<string>
     */
    private function labels(HeldView $view, array $fields): array
    {
        return array_column($this->model->columns($view, $fields), 'label');
    }

    /**
     * The view's condition in words: each term as „{oszlop}: {értékek}”, joined with „; ”; the parameter term
     * names the value given at the grant. Null for a view without one.
     *
     * @param array<string, FieldDefinition> $fields
     */
    private function filterText(ViewShape $shape, array $fields, int $eventId): ?string
    {
        $terms = [];
        foreach ($shape->filter as $term) {
            $label = $this->label($term['column'], $fields, $eventId);
            $terms[] = $term['in'] === null
                ? $label . ': ' . Texts::PER_GRANT
                : $label . ': ' . implode(', ', array_map(
                    fn (string $value): string => $this->valueLabel($term['column'], $value, $fields, $eventId),
                    $term['in']
                ));
        }

        return $terms === [] ? null : implode('; ', $terms);
    }

    /** @param array<string, FieldDefinition> $fields */
    private function label(string $column, array $fields, int $eventId): string
    {
        foreach ($this->model->describe([$column], $fields, $eventId) as $meta) {
            return $meta['label'];
        }

        return $column;
    }

    /** @param array<string, FieldDefinition> $fields */
    private function valueLabel(string $column, string $value, array $fields, int $eventId): string
    {
        return $this->model->valueLabel($column, $value, $fields, $eventId);
    }
}
