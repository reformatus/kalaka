<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

use Kalaka\Forms\Engine\ThemeAdapters;

/**
 * What the active theme adapter gives other modules' participant-side markup, read from the one adaptation the
 * form itself uses: the error colour and the form's submit button class. No second source.
 */
final class ThemeStyle
{
    /** The submit button's own class, before the adapter's: the form block and every other button use this. */
    public const BUTTON_CLASS = 'wp-element-button';

    private function __construct()
    {
    }

    /** The adapter's error colour (`#rgb`, `#rrggbb` or `var(--name)`), or null to keep the theme's own. */
    public static function errorColor(): ?string
    {
        return ThemeAdapters::current()->errorColour;
    }

    /** The class attribute value of a submit button: `wp-element-button` and the adapter's classes (`et_pb_button` on Divi). */
    public static function buttonClass(): string
    {
        return implode(' ', [self::BUTTON_CLASS, ...ThemeAdapters::current()->classes(ElementRole::SubmitButton)]);
    }
}
