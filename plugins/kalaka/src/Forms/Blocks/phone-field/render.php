<?php

/**
 * Server render of the phone field: events-and-forms.md, "Forms implementation". The markup is
 * `Kalaka\Forms\FieldMarkup`'s, with the theme adapter's classes and wrapper.
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

use Kalaka\Forms\FieldMarkup;

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FieldMarkup escapes what it builds
echo FieldMarkup::textLike($attributes, 'tel', FieldMarkup::flag($attributes, 'required'), false, 'tel');
