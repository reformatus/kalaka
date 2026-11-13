<?php

declare(strict_types=1);

namespace Kalaka\Forms;

use Kalaka\Forms\Api\FormPage;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Which blocks each block editor may insert (`allowed_block_types_all`). The gated blocks are the
 * contributed field types' blocks and `FORM_ONLY_BLOCKS`. A `kalaka_form` editor gets an allow-list:
 * the gated blocks, `CONDITION_BLOCKS` and `FORM_CORE_BLOCKS`, nothing else (no form block: a form never
 * embeds a form). In any other editor a gated block is allowed only when a `BlockHost` that applies to it
 * names it; the condition blocks, the form block and every other block are left as they are. What another
 * filter allowed is kept: a list is only narrowed, except that a form's editor always gets its gated and
 * condition blocks back.
 */
final class BlockGate
{
    /**
     * Form-only blocks that are not field types: the after-submit section (`Api\AfterSubmit`, registered
     * here) and the form's pages (`kalaka/form-page`, registered by `Forms\Module`). Another editor gets
     * them only through a `BlockHost`.
     */
    public const FORM_ONLY_BLOCKS = [
        'kalaka/form-page',
        'kalaka/after-submit',
    ];

    /**
     * The condition blocks: not gated, so every block editor offers them (#53 N4, owner 2026-11-13); a
     * form's editor gets them in its allow-list.
     */
    public const CONDITION_BLOCKS = [
        'kalaka/if',
        'kalaka/else-if',
        'kalaka/else',
    ];

    /** The core blocks a form may hold. */
    public const FORM_CORE_BLOCKS = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/list-item',
        'core/separator',
        'core/image',
        'core/group',
    ];

    /** The block that embeds a form in a page. */
    public const FORM_BLOCK = FormPage::BLOCK;

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
            $core = $allowed === true ? self::FORM_CORE_BLOCKS : array_intersect(self::FORM_CORE_BLOCKS, $names);

            return array_values(array_unique([...$gated, ...self::CONDITION_BLOCKS, ...$core]));
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
