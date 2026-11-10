<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Which of Kaláka's form blocks each block editor may insert (`allowed_block_types_all`). The gated
 * blocks are the contributed field types' blocks and `FORM_ONLY_BLOCKS`. In a `kalaka_form` they are
 * all allowed, with every other block, except the form block itself (a form never embeds a form). In
 * any other editor a gated block is allowed only when a `BlockHost` that applies to it names it; the
 * form block and every other block are left as they are. What another filter allowed is kept: a list
 * is only narrowed, except that a form's editor always gets its gated blocks back.
 */
final class BlockGate
{
    /** Form-only blocks that are not field types (the condition blocks join with values and conditions). */
    public const FORM_ONLY_BLOCKS = [];

    /** The block that embeds a form in a page. */
    public const FORM_BLOCK = 'kalaka/form';

    /**
     * The `allowed_block_types_all` filter.
     *
     * @param bool|array<string> $allowed
     * @return bool|list<string>
     */
    public static function filter(bool|array $allowed, \WP_Block_Editor_Context $context): bool|array
    {
        if ($allowed === false) {
            return false;
        }
        $postType = $context->post instanceof \WP_Post ? $context->post->post_type : null;

        return self::allowed($allowed, $postType, $context->name, Contributions::current());
    }

    /**
     * @param true|array<string> $allowed
     * @return list<string>
     */
    public static function allowed(
        bool|array $allowed,
        ?string $postType,
        ?string $editor,
        Contributions $contributions
    ): array {
        $gated = array_values(array_unique([...array_keys($contributions->fieldTypes()), ...self::FORM_ONLY_BLOCKS]));
        $names = $allowed === true
            ? array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered())
            : array_values(array_filter($allowed, 'is_string'));

        if ($postType === FormPosts::POST_TYPE) {
            $names = array_merge(array_diff($names, [self::FORM_BLOCK]), $gated);

            return array_values(array_unique($names));
        }

        $hosted = [];
        foreach ($contributions->blockHosts() as $host) {
            if ($host->appliesTo($postType, $editor)) {
                $hosted = [...$hosted, ...$host->blocks];
            }
        }

        return array_values(array_diff($names, array_diff($gated, $hosted)));
    }
}
