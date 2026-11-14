<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

/** The stored view of an event: a name, a row filter and a column set. Its event never changes. */
final class View
{
    public function __construct(
        public readonly int $eventId,
        public string $name,
        public ViewShape $shape,
        public readonly int $id = 0,
        public int $version = 0,
    ) {
    }

    /** @param array<string, ?string> $row a `kalaka_views` row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['event_id'],
            (string) $row['name'],
            ViewShape::fromStored((string) $row['filter'], (string) $row['columns']),
            (int) $row['id'],
            (int) $row['version'],
        );
    }
}
