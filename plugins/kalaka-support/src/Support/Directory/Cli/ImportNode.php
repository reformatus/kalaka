<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory\Cli;

/**
 * One node of an import file while DirectoryImport works on it: a row's node, or an ancestor the
 * rows name without a row of its own (implied). Lives only during the import.
 */
final class ImportNode
{
    /** @var list<int> the lines of the node's rows; empty for an implied node, more than one for a duplicate */
    public array $lines = [];

    /** the `id` cell of the node's row; empty when the ID is derived */
    public string $id = '';

    /** the resolved external ID; null while unknown */
    public ?string $externalId = null;

    /** @var list<string> */
    public array $aliases = [];

    /** true or false, or the cell as it is, for the use case's schema to refuse */
    public bool|string $active = true;

    /** why the node failed; null while it has not */
    public ?string $error = null;

    /** @var null|'created'|'updated'|'unchanged' */
    public ?string $outcome = null;

    /**
     * @param int $depth the number of ancestors named in the file
     * @param ?string $parent the parent's label path, null for a top-level node
     * @param int $line the line of the node's row, or of the first row that implies it
     */
    public function __construct(
        public readonly int $depth,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $parent,
        public int $line,
    ) {
    }

    public function implied(): bool
    {
        return $this->lines === [];
    }
}
