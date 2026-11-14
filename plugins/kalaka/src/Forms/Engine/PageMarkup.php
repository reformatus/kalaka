<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Engine\Condition\ChainMarks;

/**
 * What a page block (`kalaka/form-page`) renders. In a form page that renders the
 * marked blocks of `Api\Forms::blocks()`: its wrapper, `<div data-kalaka-page="N" hidden>`, with
 * `data-kalaka-terminal` on a terminal page, its title, if it has one, and its blocks; every page is hidden
 * and the script shows the current one. Anywhere else (`Values::render()`, an unmarked block): nothing.
 *
 * The title is the theme's own heading (`h2`), focusable by the script when the page changes; nothing
 * of ours styles it (events-and-forms.md, "Forms implementation": the look follows the theme).
 */
final class PageMarkup
{
    public static function render(\WP_Block $block, string $content): string
    {
        $page = ChainMarks::pageOf($block->parsed_block);
        if ($page === null) {
            return '';
        }

        $attributes = $block->parsed_block['attrs'] ?? [];
        $label = is_string($attributes['label'] ?? null) ? trim($attributes['label']) : '';
        $title = $label === '' ? '' : '<h2 data-kalaka-page-title>' . wp_kses_post($label) . '</h2>';
        $terminal = ($attributes['terminal'] ?? false) === true ? ' data-kalaka-terminal' : '';

        return sprintf('<div data-kalaka-page="%d"%s hidden>%s%s</div>', $page, $terminal, $title, $content);
    }
}
