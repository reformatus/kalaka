<?php

/**
 * Server render of a page of a multi-page form: events-and-forms.md, "Forms implementation". See
 * `PageMarkup`: on a form page, its hidden wrapper with its number; anywhere else nothing.
 *
 * @var string $content the rendered inner blocks
 * @var \WP_Block $block
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- blocks WordPress has rendered, in a wrapper of integers and a kses-filtered title
echo \Kalaka\Forms\PageMarkup::render($block, $content);
