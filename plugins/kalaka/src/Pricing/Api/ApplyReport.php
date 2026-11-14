<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

/** The result of a money settings apply, one item per registration it re-evaluated (foundation.md, "Bulk changes"). */
final readonly class ApplyReport
{
    /** @param list<ApplyItem> $items */
    public function __construct(public array $items)
    {
    }

    /** @return list<ApplyItem> */
    public function failed(): array
    {
        return array_values(array_filter($this->items, static fn (ApplyItem $item): bool => !$item->isDone()));
    }

    /** @return list<ApplyItem> */
    public function done(): array
    {
        return array_values(array_filter($this->items, static fn (ApplyItem $item): bool => $item->isDone()));
    }
}
