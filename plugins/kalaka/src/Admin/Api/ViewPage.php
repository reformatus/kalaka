<?php

declare(strict_types=1);

namespace Kalaka\Admin\Api;

/** One page of a view: its columns, the rows of the page and how many rows the view has in all. */
final readonly class ViewPage
{
    /**
     * @param list<array{column: string, write: bool}> $columns
     * @param list<ViewRow> $rows
     */
    public function __construct(public array $columns, public array $rows, public int $total)
    {
    }
}
