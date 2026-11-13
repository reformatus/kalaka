<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Condition\ChainMarks;
use Kalaka\Forms\Value\RenderStack;

/**
 * What a branch block (`kalaka/if`, `kalaka/else-if`, `kalaka/else`) renders.
 *
 * - In a document `Values::render()` (a mail) or `Values::renderPage()` (an ordinary page) renders: only
 *   the held branch's inner blocks, with no wrapper; any other branch nothing.
 * - In a form page that renders the marked blocks of `Api\Forms::blocks()`: a branch in a wrapper that
 *   carries the chain number and the branch index from `ChainMarks`, hidden by default (events-and-forms.md,
 *   "Forms implementation"); the page shows the branch that `FormAvailability::$chains` holds. The one
 *   branch a withheld chain ships is marked shown and has no `hidden`. [UNAPPROVED N11]
 * - Anything else: nothing.
 */
final class BranchMarkup
{
    public static function render(\WP_Block $block, string $content): string
    {
        // The marks first: a form page may render its marked blocks inside a value context (its inline
        // event values), and its branches must still render as the page's wrappers.
        $marks = ChainMarks::of($block->parsed_block);
        if ($marks === null) {
            return RenderStack::isActive() && RenderStack::rendersBranch($block) ? $content : '';
        }

        $shown = ($block->parsed_block['attrs'][ChainMarks::SHOWN_ATTRIBUTE] ?? false) === true;

        return sprintf(
            '<div data-kalaka-chain="%d" data-kalaka-branch="%d"%s>%s</div>',
            $marks[0],
            $marks[1],
            $shown ? '' : ' hidden',
            $content
        );
    }
}
