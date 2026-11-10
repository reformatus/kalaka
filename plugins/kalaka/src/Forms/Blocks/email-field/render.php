<?php

/**
 * Server render of the email field: events-and-forms.md, "Forms implementation". The markup is
 * `Kalaka\Forms\FieldMarkup`'s, with the theme adapter's classes and wrapper.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\FieldMarkup;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
echo FieldMarkup::textLike(
    $attributes,
    'email',
    FieldMarkup::flag($attributes, 'required') || FieldMarkup::flag($attributes, 'contactEmail')
);
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
