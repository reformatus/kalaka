<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine\Value;

use Kalaka\Forms\Api\ValueContext;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The placeholders typed by hand, `{{key}}`, in the text and in the attributes of a document's HTML
 * (owner 2026-11-26, N960 option (a)): found by one reader, `tokens()`, which the save check, the placed keys and the
 * fill all use, and filled with the HTML API. A key is what stands between the braces, trimmed.
 *
 * - Text: the value's text goes in as plain text, which the HTML API escapes.
 * - A URL attribute (`URL_ATTRIBUTES`): a placeholder that is the whole attribute is the URL, kept as `esc_url_raw()`
 *   leaves it; one inside a longer URL is the value percent-encoded, so a value never adds a query or a path.
 * - An event handler attribute (`on…`): the placeholder is replaced with nothing; a value never reaches a script.
 * - Any other attribute: the value as plain text, which the HTML API escapes.
 *
 * A value is never scanned for placeholders: `fill()` runs on the document's HTML before the values of bindings and
 * inline values are put in, and replaces in one pass. Text inside `<script>`, `<style>`, `<title>` and `<textarea>`
 * is not text for the HTML API's text tokens and is not read.
 */
final class Placeholders
{
    private const PATTERN = '/\{\{([^{}]*)\}\}/';

    /** The attributes whose value is a URL. */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'cite'];

    /**
     * Every placeholder of the HTML as it is typed, in order, and every opening `{{` that no `}}` closes in the same
     * text or attribute (a placeholder split by markup, which the fill could not replace): that one as `{{`.
     *
     * @return list<array{string, string}> the typed text and the key (the typed text is `{{`, with an empty key,
     *     for an opening left open)
     */
    public function tokens(string $html): array
    {
        $found = [];
        $this->walk($html, static function (string $value) use (&$found): ?string {
            if (preg_match_all(self::PATTERN, $value, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $found[] = [$match[0], trim($match[1])];
                }
            }
            if (str_contains((string) preg_replace(self::PATTERN, '', $value), '{{')) {
                $found[] = ['{{', ''];
            }

            return null;
        });

        return $found;
    }

    /**
     * The HTML with every placeholder replaced by its value in the context. A key the context does not declare is
     * a failure of the act that renders: a mail is failed, a page or a form fails to render (owner 2026-11-27,
     * walkthrough 8.11 a), never empty and never raw.
     *
     * @throws SystemFailure a key the context does not declare
     */
    public function fill(string $html, ValueContext $context): string
    {
        if (!str_contains($html, '{{')) {
            return $html;
        }

        return $this->walk($html, fn (string $value, ?string $attribute): string => $this->replaced(
            $value,
            $attribute,
            $context
        ));
    }

    /**
     * The same for the blocks of a render: the HTML pieces of every block, and of its inner blocks, filled. With
     * `$kalakaOnly` (an ordinary page) only the blocks inside a Kaláka block are filled and plain text outside them
     * is left alone, as the save check (`kalakaHtml()`) reads only those.
     *
     * @param list<array<string, mixed>> $blocks parsed blocks
     * @return list<array<string, mixed>>
     * @throws SystemFailure as `fill()`
     */
    public function fillBlocks(
        array $blocks,
        ValueContext $context,
        bool $kalakaOnly = false,
        bool $inside = false
    ): array {
        foreach ($blocks as $index => $block) {
            $within = !$kalakaOnly || $inside || self::isKalaka($block);
            if ($within && is_array($block['innerContent'] ?? null)) {
                $block['innerContent'] = array_map(
                    fn ($piece) => is_string($piece) ? $this->fill($piece, $context) : $piece,
                    $block['innerContent']
                );
            }
            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                $block['innerBlocks'] = $this->fillBlocks(
                    array_values($block['innerBlocks']),
                    $context,
                    $kalakaOnly,
                    $within
                );
            }
            $blocks[$index] = $block;
        }

        return $blocks;
    }

    /**
     * The HTML of a page that a Kaláka block holds, itself and everything inside it, in document order: the part
     * of an ordinary page whose placeholders are Kaláka's (checked at save, filled at render); plain text outside a
     * Kaláka block is not Kaláka's and is left alone.
     */
    public function kalakaHtml(string $content): string
    {
        $html = '';
        $collect = function (array $blocks, bool $inside) use (&$collect, &$html): void {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $within = $inside || self::isKalaka($block);
                if ($within) {
                    $html .= implode('', array_filter($block['innerContent'] ?? [], 'is_string'));
                }
                $collect(array_values($block['innerBlocks'] ?? []), $within);
            }
        };
        $collect(array_values(parse_blocks($content)), false);

        return $html;
    }

    /** @param array<string, mixed> $block */
    private static function isKalaka(array $block): bool
    {
        $name = $block['blockName'] ?? null;

        return is_string($name) && str_starts_with($name, 'kalaka/');
    }

    /**
     * Walks the text tokens and the attributes of the HTML; the callback gets each one's text (decoded) and, for an
     * attribute, its name, and returns the text to put back or null to leave it.
     *
     * @param \Closure(string, ?string): ?string $visit
     */
    private function walk(string $html, \Closure $visit): string
    {
        $processor = new \WP_HTML_Tag_Processor($html);
        while ($processor->next_token()) {
            $type = $processor->get_token_type();
            if ($type === '#text') {
                $text = $processor->get_modifiable_text();
                $new = str_contains($text, '{{') || str_contains($text, '}}') ? $visit($text, null) : null;
                if ($new !== null && $new !== $text) {
                    $processor->set_modifiable_text($new);
                }
            } elseif ($type === '#tag' && !$processor->is_tag_closer()) {
                foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $name) {
                    $value = $processor->get_attribute($name);
                    if (!is_string($value) || !str_contains($value, '{{')) {
                        continue;
                    }
                    $new = $visit($value, $name);
                    if ($new !== null && $new !== $value) {
                        $processor->set_attribute($name, $new);
                    }
                }
            }
        }

        return $processor->get_updated_html();
    }

    /** @throws SystemFailure */
    private function replaced(string $value, ?string $attribute, ValueContext $context): string
    {
        if ($attribute !== null && str_starts_with($attribute, 'on')) {
            return (string) preg_replace(self::PATTERN, '', $value);
        }
        $url = $attribute !== null && in_array($attribute, self::URL_ATTRIBUTES, true);
        $whole = $url && preg_match('/\A\s*\{\{[^{}]*\}\}\s*\z/', $value) === 1;
        $out = (string) preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($context, $url, $whole): string {
                $text = $context->get(trim($match[1]))->text;

                return $url && !$whole ? rawurlencode($text) : $text;
            },
            $value
        );

        return $url ? esc_url_raw(trim($out)) : $out;
    }
}
