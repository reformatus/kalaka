<?php

declare(strict_types=1);

namespace Kalaka\Admin\View;

/**
 * The stored view of an event: a name, a row filter, a column set and the counted members of its sum row.
 * Its event never changes. A master row (`master`, S37) is the event's master view: it holds only the
 * counted members; its name is empty and its shape has no filter and no columns (`ViewShape::forMaster()`).
 */
final class View
{
    public function __construct(
        public readonly int $eventId,
        public string $name,
        public ViewShape $shape,
        public readonly int $id = 0,
        public int $version = 0,
        public readonly bool $master = false,
    ) {
    }

    /**
     * A new master row of the event, holding the given counts.
     *
     * @param array<string, list<string>> $counts
     */
    public static function newMaster(int $eventId, array $counts): self
    {
        return new self($eventId, '', ViewShape::forMaster($counts), 0, 0, true);
    }

    /** @param array<string, ?string> $row a `kalaka_views` row */
    public static function fromRow(array $row): self
    {
        $master = (int) ($row['master'] ?? 0) === 1;

        return new self(
            (int) $row['event_id'],
            (string) $row['name'],
            $master
                ? ViewShape::fromStoredMaster($row['counts'] ?? null)
                : ViewShape::fromStored((string) $row['filter'], (string) $row['columns'], $row['counts'] ?? null),
            (int) $row['id'],
            (int) $row['version'],
            $master,
        );
    }
}
