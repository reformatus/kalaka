<?php

/**
 * Server render of a condition branch: events-and-forms.md, "Conditions". `Values::render()` keeps
 * only the held branch of each chain and marks it, so a branch renders its inner blocks, with no wrapper,
 * only when marked and while a `Values::render()` or `Values::renderPage()` call is in progress; any other
 * branch renders nothing.
 *
 * @var string $content the rendered inner blocks
 * @var \WP_Block $block
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (\Kalaka\Forms\Value\RenderStack::rendersBranch($block)) {
    echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blocks WordPress has rendered
}
