<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Admin\Grid\FieldDefinitions;
use Kalaka\Admin\Grid\GridNarrowing;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Actor\Actor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\NotFound;
use Kalaka\Foundation\Api\Error\PermissionDenied;

/**
 * The registrations a bulk act reaches when it names the grid's rows instead of listing codes: every row of a
 * held view that the grid's filters and search leave, across all pages (owner ruling 2026-11-26). It is the
 * grid's rows route read whole: the view comes from `Access::viewOf()` (the same held view, the same refusal),
 * the filters and the search from `GridNarrowing` (the same 400 for a bad one), and the rows from
 * `ViewQuery::codesOf()` (the very conditions of the rows route's pages). Nothing here reaches a row the view
 * hides, and nothing about the act's own permission: the act's check still runs on the codes returned.
 */
final class GridSelection
{
    public function __construct(
        private readonly Access $access = new Access(),
        private readonly ViewQuery $query = new ViewQuery(),
        private readonly GridNarrowing $narrowing = new GridNarrowing(),
        private readonly FieldDefinitions $fields = new FieldDefinitions(),
        private readonly Events $events = new Events(),
    ) {
    }

    /**
     * The codes of the rows the grid shows for the view and the narrowing now, in the grid's default order;
     * none when nothing matches.
     *
     * @param ?int $grantId the grant whose view is opened (null or 0 for full access and for none)
     * @param mixed $filters the grid's `filters`: a JSON list of `{field, operator, value}`, or the decoded
     *     list; null or '' for none
     * @param mixed $search the search text; null or '' for none
     * @return list<string>
     * @throws NotFound for an event that does not exist
     * @throws PermissionDenied when the actor holds no such view
     * @throws InvalidInput for a filter or search the grid would refuse
     */
    public function codes(
        Actor $actor,
        int $eventId,
        int $viewId,
        ?int $grantId,
        mixed $filters = null,
        mixed $search = null,
    ): array {
        $this->events->find($eventId) ?? throw new NotFound();
        $view = $this->access->viewOf($actor, $eventId, $viewId, $grantId ?? 0);

        return $this->query->codesOf(
            $view,
            $this->narrowing->of($view, $this->fields->of($eventId), $filters, $search)
        );
    }
}
