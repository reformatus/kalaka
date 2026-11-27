<?php

declare(strict_types=1);

namespace Kalaka\Admin\Grid;

/**
 * The derived menu icon of an event item (carried over from the UX menu mock): two letters from the title on the first
 * line (the first letter and the first letter of the last word, or the last letter of a one-word title), and the last
 * two digits of the title on a second line when it has any. Drawn as a 20×20 SVG data URI; `GridPage::menuStyle()`
 * paints it as a CSS mask in the menu's own icon colour.
 */
final class MenuIcon
{
    /**
     * @return array{string, string} the letters and the digits, either may be empty
     */
    public static function initials(string $title): array
    {
        $letters = strtoupper(remove_accents($title));
        $letters = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z ]/', '', $letters)));
        $line1 = '';
        if ($letters !== '') {
            $words = explode(' ', $letters);
            $last = (string) end($words);
            $line1 = strlen($letters) === 1
                ? $letters
                : $letters[0] . (count($words) > 1 ? $last[0] : substr($last, -1));
        }
        $digits = (string) preg_replace('/[^0-9]/', '', $title);

        return [$line1, substr($digits, -2)];
    }

    /** The SVG data URI for a title. */
    public static function uri(string $title): string
    {
        [$line1, $line2] = self::initials($title);
        $font = 'font-family="ui-monospace,Menlo,Consolas,&quot;DejaVu Sans Mono&quot;,'
            . '&quot;Liberation Mono&quot;,monospace"'
            . ' font-weight="700" text-anchor="middle" fill="black" font-size="11.5"';
        $text = $line2 === ''
            ? '<text x="10" y="14.1" ' . $font . '>' . esc_html($line1) . '</text>'
            : '<text x="10" y="8.6" ' . $font . '>' . esc_html($line1) . '</text>'
                . '<text x="10" y="19.6" ' . $font . '>' . esc_html($line2) . '</text>';

        return 'data:image/svg+xml;base64,'
            . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">' . $text . '</svg>');
    }
}
