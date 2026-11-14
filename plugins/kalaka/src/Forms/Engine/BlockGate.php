<?php

declare(strict_types=1);

namespace Kalaka\Forms\Engine;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * Which blocks each block editor may insert (`allowed_block_types_all`). The gated blocks are the
 * contributed field types' blocks and `FORM_ONLY_BLOCKS`. A `kalaka_form` editor gets an allow-list:
 * the gated blocks, `CONDITION_BLOCKS` and `FORM_CORE_BLOCKS`, nothing else (no form block: a form never
 * embeds a form). In any other editor a gated block is allowed only when a `BlockHost` that applies to it
 * names it; the condition blocks, the form block and every other block are left as they are. What another
 * filter allowed is kept: a list is only narrowed, except that a form's editor always gets its gated and
 * condition blocks back. Outside a form's editor an allow-all `true` stays `true`: the
 * gated blocks are hidden there on the client.
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
    public const FORM_BLOCK = FormCaching::BLOCK;

    /**
     * The `allowed_block_types_all` filter.
     *
     * @param bool|array<string> $allowed
     * @return bool|list<string>
     */
    public static function filter(bool|array $allowed, \WP_Block_Editor_Context $context): bool|array
    {
        $postType = $context->post instanceof \WP_Post ? $context->post->post_type : null;

        return self::allowed($allowed, $postType, $context->name, Contributions::current());
    }

    /**
     * What an editor may insert. A form's editor: the allow-list (narrowed by another filter's list). Any
     * other editor: `true` and `false` stay as they are, because turning "everything" into the server's
     * list would hide blocks registered only in JavaScript, site-wide; a list loses the gated blocks no
     * host allows there. With `true`, the gated blocks are hidden on the client (`enqueueHiding()`).
     *
     * @param bool|array<string> $allowed
     * @return bool|list<string>
     */
    public static function allowed(
        bool|array $allowed,
        ?string $postType,
        ?string $editor,
        Contributions $contributions
    ): bool|array {
        if ($allowed === false) {
            return false;
        }
        $gated = self::gated($contributions);
        if ($postType === FormPosts::POST_TYPE) {
            $core = $allowed === true
                ? self::FORM_CORE_BLOCKS
                : array_intersect(self::FORM_CORE_BLOCKS, array_filter($allowed, 'is_string'));

            return array_values(array_unique([...$gated, ...self::CONDITION_BLOCKS, ...$core]));
        }
        if ($allowed === true) {
            return true;
        }

        return array_values(array_diff(
            array_filter($allowed, 'is_string'),
            self::hidden($postType, $editor, $contributions)
        ));
    }

    /**
     * The gated blocks an editor other than a form's must not offer: those no `BlockHost` names for it.
     *
     * @return list<string>
     */
    public static function hidden(?string $postType, ?string $editor, Contributions $contributions): array
    {
        if ($postType === FormPosts::POST_TYPE) {
            return [];
        }
        $hosted = [];
        foreach ($contributions->blockHosts() as $host) {
            if ($host->appliesTo($postType, $editor)) {
                $hosted = [...$hosted, ...$host->blocks];
            }
        }

        return array_values(array_diff(self::gated($contributions), $hosted));
    }

    /**
     * `enqueue_block_editor_assets`: in an editor other than a form's, where the allowed blocks stay
     * `true`, the names of the gated blocks are printed before the Kaláka editor script (the form block's) as
     * `window.kalakaHiddenBlocks`; that built script unregisters them once every editor script has
     * registered its blocks (`Blocks/form/hidden.js`), so the inserter does not offer them. Only data is
     * printed here. The server list stays untouched.
     */
    public static function enqueueHiding(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $postType = $screen instanceof \WP_Screen && $screen->post_type !== '' ? $screen->post_type : null;
        $editor = match ($screen instanceof \WP_Screen ? $screen->base : null) {
            'post' => 'core/edit-post',
            'site-editor' => 'core/edit-site',
            'widgets' => 'core/edit-widgets',
            'customize' => 'core/customize-widgets',
            default => null,
        };
        $hidden = self::hidden($postType, $editor, Contributions::current());
        if ($hidden === []) {
            return;
        }
        $handle = self::editorHandle();
        if ($handle === null) {
            return;
        }
        $names = (string) wp_json_encode($hidden);
        wp_add_inline_script($handle, 'window.kalakaHiddenBlocks = ' . $names . ';', 'before');
    }

    /** The handle of the Kaláka editor script, the one every Kaláka block's `block.json` names; null before registration. */
    public static function editorHandle(): ?string
    {
        $type = \WP_Block_Type_Registry::get_instance()->get_registered(self::FORM_BLOCK);
        $handle = $type === null ? null : ($type->editor_script_handles[0] ?? null);

        return is_string($handle) ? $handle : null;
    }

    /** @return list<string> */
    private static function gated(Contributions $contributions): array
    {
        return array_values(array_unique([...array_keys($contributions->fieldTypes()), ...self::FORM_ONLY_BLOCKS]));
    }
}
