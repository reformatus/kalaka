<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

use Kalaka\Foundation\Api\Error\InvalidInput;

/** One aggregate of `ViewQuery::aggregates()`: a listed column, the kind, and for `members` the values to count. */
final readonly class AggregateSpec
{
    /**
     * @param list<string> $members the values to count, for `members` only (at least one)
     * @throws InvalidInput when the kind does not fit the column or the members
     */
    public function __construct(public string $column, public AggregateKind $kind, public array $members = [])
    {
        $field = str_starts_with($column, 'field:');
        $fits = match ($kind) {
            AggregateKind::Rows => true,
            AggregateKind::Members => $members !== []
                && ($column === 'status' || $field || str_starts_with($column, 'decision:')),
            AggregateKind::Yes => $field || $column === 'awaits_answer',
            AggregateKind::Sum, AggregateKind::Filled, AggregateKind::Average => $field,
        };
        if (!$fits || ($kind !== AggregateKind::Members && $members !== [])) {
            throw new InvalidInput();
        }
    }
}
