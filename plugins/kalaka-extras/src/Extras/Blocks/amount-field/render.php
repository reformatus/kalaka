<?php

/**
 * Server render of the amount field (events-and-forms.md, "Forms implementation"): core's text field markup
 * (`Kalaka\Forms\Api\FieldMarkup::textLike()`), with the theme adapter's classes and wrapper, and nothing of
 * its own. The look is UX's to prototype; built plain meanwhile. [UNAPPROVED N7]
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Api\FieldMarkup;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
echo FieldMarkup::textLike($attributes, FieldMarkup::flag($attributes, 'required'));
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
