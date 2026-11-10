<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Condition\ChainMarks;
use Kalaka\Forms\Value\RenderStack;

/**
 * What a branch block (`kalaka/if`, `kalaka/else-if`, `kalaka/else`) renders.
 *
 * - In a document `Values::render()` renders (mail, portal page): only the held branch's inner blocks,
 *   with no wrapper; any other branch nothing.
 * - In a form page that renders the marked blocks of `Api\Forms::blocks()`: every branch, in a wrapper
 *   that carries the chain number and the branch index from `ChainMarks` and is hidden by default
 *   (events-and-forms.md, "Forms implementation"). The page shows the branch that
 *   `FormAvailability::$chains` holds for its chain. [UNAPPROVED N7]
 * - Anything else: nothing.
 */
final class BranchMarkup
{
    public static function render(\WP_Block $block, string $content): string
    {
        if (RenderStack::isActive()) {
            return RenderStack::rendersBranch($block) ? $content : '';
        }

        $marks = ChainMarks::of($block->parsed_block);
        if ($marks === null) {
            return '';
        }

        return sprintf(
            '<div data-kalaka-chain="%d" data-kalaka-branch="%d" hidden>%s</div>',
            $marks[0],
            $marks[1],
            $content
        );
    }
}
