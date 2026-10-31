<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

final readonly class Schema
{
    /**
     * @param list<string> $tables CREATE TABLE statements for dbDelta; table
     *     names use the literal placeholder {prefix}, replaced by the upgrader
     *     with $wpdb->prefix. Write them in dbDelta's own format (two spaces
     *     after PRIMARY KEY, KEY not INDEX, lowercase types): the upgrader
     *     re-runs dbDelta as a dry run and fails if it still finds changes.
     * @param array<int, callable(): void> $steps Keyed by the version they
     *     upgrade to.
     */
    public function __construct(
        public int $version,
        public array $tables = [],
        public array $steps = [],
    ) {
    }

    public static function none(): self
    {
        return new self(0);
    }
}
