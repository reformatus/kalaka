<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Decision;

/**
 * The decisions table as another module may read it by name, and the "current decision" rule over it
 * (the latest record of a kind, by `id`), owned here so a set-based reader cannot drift from
 * `DecisionQuery`. Only Foundation and the admin view query may use this class (deptrac's "Decision
 * tables" layer; engineering.md, "Static checks").
 */
final class DecisionTables
{
    /** Append-only decision records, keyed `id`, indexed by `event_id`, `code`, `kind`. */
    public const DECISIONS = 'kalaka_decisions';

    /**
     * The state of the current decision of one kind for the registration row `$row`, as a subquery. Its
     * arguments, in placeholder order: the decisions table twice (`%i`, `%i`), then the kind (`%s`).
     *
     * @param literal-string $row the registrations table's alias
     * @param literal-string $outer the alias of the decision whose state is read
     * @param literal-string $inner the alias that finds the latest record
     * @return literal-string
     */
    public static function currentState(string $row, string $outer, string $inner): string
    {
        return "(SELECT {$outer}.state FROM %i {$outer} WHERE {$outer}.event_id = {$row}.event_id"
            . " AND {$outer}.id = (SELECT MAX({$inner}.id) FROM %i {$inner} WHERE {$inner}.event_id = {$row}.event_id"
            . " AND {$inner}.code = {$row}.code AND {$inner}.kind = %s))";
    }
}
