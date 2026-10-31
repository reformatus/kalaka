<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Module;

interface Module
{
    /** [a-z][a-z0-9_]*, used in kalaka_schema_<id>. */
    public function id(): string;

    /** Supported core API range, e.g. '>=0.1 <0.2'. */
    public function requiresApi(): string;

    public function schema(): Schema;

    /**
     * The per-event actions this module offers, picked by grants; not WordPress capabilities.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    public function contribute(Contributions $to): void;

    public function boot(): void;
}
