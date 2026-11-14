<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Engine\Condition\Condition;

/**
 * a page of a form's definition (`kalaka/form-page`, top level only): its number
 * (from 0 in document order, as `Engine\Condition\ChainMarks` marks it), its optional title, whether it is a
 * terminal page and its condition (none for page 0). The condition is a parsed `Condition` of the form
 * engine (`Engine\Condition`); outside the engine a page is passed along, never inspected.
 */
final readonly class PageDefinition
{
    public function __construct(
        public int $index,
        public string $label,
        public bool $terminal,
        public ?Condition $condition,
    ) {
    }
}
