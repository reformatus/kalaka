<?php

/**
 * Server render of a condition branch: events-and-forms.md, "Conditions". See `BranchMarkup`: in a
 * `Values::render()` or `Values::renderPage()` document only the held branch's inner blocks; in a form
 * page's marked blocks every branch in a hidden wrapper carrying its chain and branch numbers.
 *
 * @var string $content the rendered inner blocks
 * @var \WP_Block $block
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blocks WordPress has rendered, in a wrapper of integers
echo \Kalaka\Forms\BranchMarkup::render($block, $content);
