<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\FormCaching;

/**
 * The forms a page's content places: the embed block `kalaka/form` (also nested) and the `[kalaka_form]`
 * shortcode (not an escaped `[[kalaka_form …]]`), as their form IDs, each once, in the order they appear.
 */
final class PlacedForms
{
    /** @return list<int> */
    public static function in(string $content): array
    {
        $ids = self::fromBlocks(parse_blocks($content));
        if (str_contains($content, '[' . FormCaching::SHORTCODE)) {
            $pattern = '/' . get_shortcode_regex([FormCaching::SHORTCODE]) . '/';
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if ($m[1] === '[' && $m[6] === ']') {
                    // An escaped `[[kalaka_form …]]` is literal text, as `do_shortcode_tag()` leaves it.
                    continue;
                }
                $id = absint(shortcode_parse_atts($m[3])['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<array-key, mixed> $blocks
     * @return list<int>
     */
    private static function fromBlocks(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['blockName'] ?? null) === FormCaching::BLOCK) {
                $id = absint($block['attrs']['formId'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            if (is_array($block['innerBlocks'] ?? null)) {
                $ids = [...$ids, ...self::fromBlocks($block['innerBlocks'])];
            }
        }

        return $ids;
    }
}
