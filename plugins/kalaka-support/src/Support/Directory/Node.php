<?php

declare(strict_types=1);

namespace Kalaka\Support\Directory;

/**
 * The stored node of the organization directory. A node is never deleted; `parentId` is null for
 * a root.
 */
final class Node
{
    /** An external ID: printable ASCII without whitespace, so the `ascii_bin` column never refuses one. */
    public const EXTERNAL_ID = '\A[\x21-\x7E]+\z';

    /** @param list<string> $aliases */
    public function __construct(
        public string $externalId,
        public ?int $parentId,
        public string $type,
        public string $name,
        public array $aliases = [],
        public bool $active = true,
        public int $id = 0,
        public int $version = 0,
    ) {
    }
}
