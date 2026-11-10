<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

/**
 * Which of Kaláka's blocks the block editor offers in a mail document: the condition blocks and the
 * body's place in a layout, never a form field block. Core blocks and other post types are left as they are.
 * // [UNAPPROVED N12] Kaláka's blocks are allowed per post type, not by `ancestor`. Stand-in until #52's
 * // per-post-type gate (`allowed_block_types_all`, with a point for other modules) lands; then Mail
 * // allows these blocks through that point and this filter goes.
 */
final class MailBlockGate
{
    /** Kaláka's blocks a mail document may hold. */
    public const MAIL_BLOCKS = ['kalaka/if', 'kalaka/else-if', 'kalaka/else', LayoutSlot::BLOCK];

    /**
     * @internal the `allowed_block_types_all` filter
     * @param bool|list<string> $allowed
     * @return bool|list<string>
     */
    public static function filter(bool|array $allowed, \WP_Block_Editor_Context $context): bool|array
    {
        $isMail = $context->post instanceof \WP_Post && $context->post->post_type === MailPosts::POST_TYPE;
        if ($allowed === false || !$isMail) {
            return $allowed;
        }
        $names = $allowed === true
            ? array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered())
            : $allowed;

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => !str_starts_with($name, 'kalaka/')
                || in_array($name, self::MAIL_BLOCKS, true)
        ));
    }
}
