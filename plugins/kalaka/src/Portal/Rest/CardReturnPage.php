<?php

declare(strict_types=1);

namespace Kalaka\Portal\Rest;

use Kalaka\Forms\Api\ThemeStyle;

/**
 * The page the popup's payment tab comes back to (`GET kalaka-portal/v1/card-payment/return?popup=1`): inside the
 * theme, or bare when the filter `kalaka_card_return_bare` says so. On load its script tells the opener
 * `{type: 'kalaka-card-return'}` (same origin only) and tries to close the tab; when the tab stays open, it shows a
 * line, a close button and a link back to the status page.
 */
final class CardReturnPage
{
    /** The filter that renders the page bare (a minimal document, no theme): a bool, false by default. */
    public const BARE_FILTER = 'kalaka_card_return_bare';

    private const SCRIPT = "(function () {\n"
        . "var button = document.querySelector('[data-kalaka-close]');\n"
        . "if (button) { button.addEventListener('click', function () { window.close(); }); }\n"
        . "try { window.opener && window.opener.postMessage({type: 'kalaka-card-return'}, location.origin); }"
        . " catch (e) {}\n"
        . "window.close();\n"
        . '})();';

    private function __construct()
    {
    }

    /** The whole page as text: the theme's header and footer around the body, or a bare document. */
    public static function render(string $statusPage): string
    {
        $bare = (bool) apply_filters(self::BARE_FILTER, false);
        ob_start();
        try {
            if ($bare) {
                self::bare($statusPage);
            } else {
                self::inTheme($statusPage);
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /** The opening of a document: doctype, language, charset and viewport (the escaped values are core's own). */
    private static function open(): string
    {
        return '<!doctype html><html ' . get_language_attributes() // phpcs:ignore WordPress.Security.EscapeOutput
            . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">';
    }

    private static function bare(string $statusPage): void
    {
        echo self::open() // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
            . '<title>' . esc_html(get_bloginfo('name')) . '</title></head><body>';
        self::body($statusPage);
        echo '</body></html>';
    }

    private static function inTheme(string $statusPage): void
    {
        if (wp_is_block_theme()) {
            // A block theme has no header.php: `get_header()` would fall back to the deprecated compat file.
            echo self::open(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
            wp_head();
            echo '</head><body class="' . esc_attr(implode(' ', get_body_class())) . '">';
            wp_body_open();
            echo '<div class="wp-site-blocks">';
            block_template_part('header');
            self::body($statusPage);
            block_template_part('footer');
            echo '</div>';
            wp_footer();
            echo '</body></html>';

            return;
        }
        get_header();
        self::body($statusPage);
        get_footer();
    }

    private static function body(string $statusPage): void
    {
        // [UNAPPROVED T891] the line, [UNAPPROVED T892] the close button, [UNAPPROVED T893] the link to the status page
        // (UX's texts, lektored; T893 is the status block's heading)
        echo '<main class="kalaka-card-return"><p class="kalaka-card-return__text">'
            . esc_html('Bezárhatod ezt a lapot.') . '</p>'
            . '<p><button type="button" class="' . esc_attr(ThemeStyle::buttonClass()) . '" data-kalaka-close>'
            . esc_html('Lap bezárása') . '</button></p>'
            . '<p><a href="' . esc_url($statusPage) . '">'
            . esc_html('Jelentkezésem állapota') . '</a></p></main>';
        wp_print_inline_script_tag(self::SCRIPT);
    }
}
