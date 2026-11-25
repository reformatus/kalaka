<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Api;

/**
 * A condition as SQL with its placeholders' arguments in order: what a `RequestSource` hands the admin view query
 * so "awaits an answer" can be a filterable, sortable, countable column (`RequestSource::awaitingPredicate()`).
 * The text holds only literal SQL and `%d`, `%s` and `%i` placeholders; a value never goes into it.
 * [UNAPPROVED N669]
 */
final readonly class SqlPredicate
{
    /**
     * @param literal-string $sql
     * @param list<string|int> $args
     */
    public function __construct(public string $sql, public array $args = [])
    {
    }
}
