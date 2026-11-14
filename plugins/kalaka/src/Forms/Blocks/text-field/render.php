<?php

/**
 * Server render of the text field: events-and-forms.md, "Forms implementation". The markup is
 * `Kalaka\Forms\Engine\FieldMarkup`'s, with the theme adapter's classes and wrapper.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\Engine\FieldMarkup;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
echo FieldMarkup::textLike(
    $attributes,
    'text',
    FieldMarkup::flag($attributes, 'required'),
    FieldMarkup::flag($attributes, 'multiline')
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
