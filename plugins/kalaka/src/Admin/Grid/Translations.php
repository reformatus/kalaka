<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

/**
 * DataViews' own strings in the site's language (S37). The app bundles DataViews, whose strings are in
 * WordPress's `default` domain; WordPress translates them for its own scripts that embed DataViews. The
 * translations of `wp-editor`, the core script whose translations hold the most of them, are handed to
 * `wp.i18n` before the app runs; those of `wp-components`, a dependency of the app, WordPress loads itself.
 * On a Hungarian site Kaláka's own text, `OVERRIDES`, follows and wins: core's DataViews is older than the
 * bundled one, so some strings have no translation, and some of core's read wrong in a column filter
 * („Tartalmazza” for the choice of values, „Nem egyezik”). A string neither has stays English.
 */
final class Translations
{
    /** The core script whose translations carry DataViews' strings. */
    private const SOURCE = 'wp-editor';

    /**
     * Kaláka's own Hungarian text for DataViews' msgids, in the `default` domain; a list is a plural's forms,
     * and a context is the part before the `\u{0004}`. Only strings the grid can show. [UNAPPROVED N6]
     *
     * @var array<string, string|list<string>>
     */
    public const OVERRIDES = [
        // Missing from core.
        'Loading' => 'Betöltés…',
        'Next month' => 'Következő hónap',
        'Previous month' => 'Előző hónap',
        'Today, %s, selected' => 'Ma, %s, kiválasztva',
        'Navigation bar' => 'Navigációs sáv',
        'Timezone: %s' => 'Időzóna: %s',
        'Keyboard shortcut: %s' => 'Billentyűparancs: %s',
        '%s Item' => ['%s jelentkező', '%s jelentkező'],
        '%1$s of %2$s Item' => ['%1$s / %2$s jelentkező', '%1$s / %2$s jelentkező'],
        '%s Item selected' => ['%s jelentkező kijelölve', '%s jelentkező kijelölve'],
        // Replacing core's, which read wrong in a column filter.
        'Includes' => 'Bármelyik',
        '<Name>%1$s includes: </Name><Value>%2$s</Value>' => '<Name>%1$s: </Name><Value>%2$s</Value>',
        'Is none of' => 'Egyik sem',
        '<Name>%1$s is none of: </Name><Value>%2$s</Value>' => '<Name>%1$s, kivéve: </Name><Value>%2$s</Value>',
        '<Name>%1$s is not: </Name><Value>%2$s</Value>' => '<Name>%1$s, kivéve: </Name><Value>%2$s</Value>',
        'Less than' => 'Kisebb',
        'Greater than' => 'Nagyobb',
        '<Name>%1$s is less than: </Name><Value>%2$s</Value>' => '<Name>%1$s: kisebb, mint </Name><Value>%2$s</Value>',
        '<Name>%1$s is greater than: </Name><Value>%2$s</Value>'
            => '<Name>%1$s: nagyobb, mint </Name><Value>%2$s</Value>',
        'Before' => 'Korábbi',
        'After' => 'Későbbi',
        '<Name>%1$s is before: </Name><Value>%2$s</Value>' => '<Name>%1$s: </Name><Value>%2$s</Value> előtt',
        '<Name>%1$s is after: </Name><Value>%2$s</Value>' => '<Name>%1$s: </Name><Value>%2$s</Value> után',
        'Past week' => 'Elmúlt hét',
        'Past month' => 'Elmúlt hónap',
        'Reset search' => 'Keresés törlése',
        'Reset' => 'Visszaállítás',
        'No results' => 'Nincs találat',
        'Conditions' => 'Szűrés módja',
        "verb\u{0004}Filter" => 'Szűrés',
        'Select all' => 'Összes kijelölése',
        // The ranges of Sorszám and of the number columns (`between`).
        'Between (inc)' => 'Között',
        '<Name>%1$s between (inc): </Name><Value>%2$s and %3$s</Value>'
            => '<Name>%1$s: </Name><Value>%2$s és %3$s között</Value>',
    ];

    /** Before the app's script: core's translations of `SOURCE` in the `default` domain, then `OVERRIDES`. */
    public static function load(string $handle): void
    {
        $json = load_script_textdomain(self::SOURCE, 'default');
        if (!is_string($json) || $json === '') {
            return;
        }
        $script = '( function ( data ) { wp.i18n.setLocaleData( data.locale_data.messages, "default" ); } )( '
            . $json . ' );';
        if (str_starts_with(determine_locale(), 'hu')) {
            $script .= ' wp.i18n.setLocaleData( ' . wp_json_encode(
                array_map(static fn (string|array $text): array => (array) $text, self::OVERRIDES),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . ', "default" );';
        }
        wp_add_inline_script($handle, $script, 'before');
    }
}
