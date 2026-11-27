<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

/**
 * DataViews' own strings in the site's language (S37). The app bundles DataViews, whose strings are in
 * WordPress's `default` domain; WordPress translates them for its own scripts that embed DataViews. The
 * translations of `wp-editor`, the core script whose translations hold the most of them, are handed to
 * `wp.i18n` before the app runs; those of `wp-components`, a dependency of the app, WordPress loads
 * itself. Kaláka writes no translations of DataViews' strings: a string core's translations lack stays
 * English.
 */
final class Translations
{
    /** The core script whose translations carry DataViews' strings. */
    private const SOURCE = 'wp-editor';

    /** Before the app's script: core's translations of `SOURCE` in the `default` domain. */
    public static function load(string $handle): void
    {
        $json = load_script_textdomain(self::SOURCE, 'default');
        if (!is_string($json) || $json === '') {
            return;
        }
        wp_add_inline_script(
            $handle,
            '( function ( data ) { wp.i18n.setLocaleData( data.locale_data.messages, "default" ); } )( '
            . $json . ' );',
            'before'
        );
    }
}
