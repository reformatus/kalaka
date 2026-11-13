<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A chain of a form's definition: its number (from 0 in document order, as on its branches), the
 * branches around it, outermost first, and its own branches in order.
 */
final readonly class FormChain
{
    /**
     * @param list<FormBranch> $outer
     * @param non-empty-list<FormBranch> $branches
     * @param int|null $page the number of the page the chain sits on; null without pages
     */
    public function __construct(
        public int $number,
        public array $outer,
        public array $branches,
        public ?int $page = null,
    ) {
    }
}
