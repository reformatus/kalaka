<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * Registrations' tables as another module may read them by name, and the sorszám order and numbering over
 * them, owned here so a reader cannot drift from `RegistrationList`. Only the admin view query may use this
 * class (deptrac's "Registration tables" layer; engineering.md, "Static checks").
 */
final class RegistrationTables
{
    /** One row per registration, keyed `event_id`, `code`. */
    public const REGISTRATIONS = 'kalaka_registrations';

    /** The participant field values, keyed `event_id`, `code`, `field_key`. */
    public const FIELD_VALUES = 'kalaka_field_values';

    /**
     * The instant the sorszám orders by: `registered_at` for a registered row, `created_at` otherwise.
     *
     * @param literal-string $alias the registrations table's alias, or '' for none
     * @return literal-string
     */
    public static function orderingTime(string $alias = ''): string
    {
        $column = $alias === '' ? '' : $alias . '.';

        return "CASE WHEN {$column}status = 'registered' THEN {$column}registered_at ELSE {$column}created_at END";
    }

    /**
     * The sorszám order: the ordering time, then the code, both in the given direction.
     *
     * @param literal-string $alias the registrations table's alias, or '' for none
     * @return literal-string
     */
    public static function serialOrder(string $alias = '', bool $descending = false): string
    {
        $direction = $descending ? ' DESC' : '';
        $column = $alias === '' ? '' : $alias . '.';

        return self::orderingTime($alias) . $direction . ', ' . $column . 'code' . $direction;
    }

    /**
     * The event's numbered registrations as a derived table (`code`, `serial_no`): counted separately
     * within `registered` and `waitlisted`, in the sorszám order (a window function; MariaDB 10.2). Its
     * arguments, in placeholder order: the registrations table (`%i`), then the event id (`%d`).
     *
     * @param string $table the registrations table
     * @return array{0: literal-string, 1: list<string|int>}
     */
    public static function serialNumbers(string $table, int $eventId): array
    {
        return [
            'SELECT q.code, ROW_NUMBER() OVER (PARTITION BY q.status ORDER BY ' . self::serialOrder('q')
            . ') AS serial_no FROM %i q WHERE q.event_id = %d'
            . " AND q.status IN ('registered', 'waitlisted')",
            [$table, $eventId],
        ];
    }
}
