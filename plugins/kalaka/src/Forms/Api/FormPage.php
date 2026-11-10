<?php

declare(strict_types=1);

namespace Kalaka\Forms\Api;

/**
 * A page that shows a Kaláka form is never cached (N6): it carries a submit token of its own, and a
 * cached copy would hand one visitor's token to the next. [UNAPPROVED N6]
 *
 * Forms calls `preventCaching()` on `template_redirect` for a singular page whose content holds the
 * form block `kalaka/form` or the `[kalaka_form]` shortcode, before any output. The form renderer
 * (p04: the block and the shortcode) calls it again when it renders, as the backstop for a form
 * placed where that check cannot see it (a widget, a template part): then the headers may be sent
 * already, but `DONOTCACHEPAGE` still stops the page-cache plugins, which read it at the end of the
 * request.
 */
final class FormPage
{
    public const BLOCK = 'kalaka/form';

    public const SHORTCODE = 'kalaka_form';

    /** `DONOTCACHEPAGE`, and while no output is sent `nocache_headers()` with `Cache-Control: no-store`. */
    public static function preventCaching(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (headers_sent()) {
            return;
        }
        nocache_headers();
        header('Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private');
    }

    /**
     * Whether the content places a form: the block or the shortcode. The shortcode is matched by its tag
     * whether or not it is registered yet (`has_shortcode()` sees only registered ones).
     */
    public static function placesForm(string $content): bool
    {
        return has_block(self::BLOCK, $content)
            || (str_contains($content, '[' . self::SHORTCODE)
                && preg_match('/' . get_shortcode_regex([self::SHORTCODE]) . '/', $content) === 1);
    }
}
