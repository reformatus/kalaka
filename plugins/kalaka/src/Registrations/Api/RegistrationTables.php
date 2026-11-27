<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * Registrations' tables as another module may read them by name, and the sorszám order over them, owned
 * here so a reader cannot drift from `RegistrationList`. Only the admin view query may use this class
 * (deptrac's "Registration tables" layer; engineering.md, "Static checks").
 */
final class RegistrationTables
{
    /** One row per registration, keyed `event_id`, `code`. */
    public const REGISTRATIONS = 'kalaka_registrations';

    /** The participant field values, keyed `event_id`, `code`, `field_key`. */
    public const FIELD_VALUES = 'kalaka_field_values';

    /** The SQL list of the active statuses: `RegistrationStatus::activeValues()`, a test holds the two together. */
    public const ACTIVE_STATUSES_SQL = "'registered'";

    /**
     * The condition that a row is an active registration (`RegistrationStatus::isActive()`).
     *
     * @param literal-string $alias the registrations table's alias, or '' for none
     * @return literal-string
     */
    public static function isActive(string $alias = ''): string
    {
        $column = $alias === '' ? '' : $alias . '.';

        return "{$column}status IN (" . self::ACTIVE_STATUSES_SQL . ')';
    }

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
}
