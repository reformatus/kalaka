<?php

declare(strict_types=1);

namespace Kalaka\Admin\Mail;

/**
 * The mail templates (`kalaka_mail` posts) in WordPress's own screens: they leave the native post list, and the
 * editor (`post.php`) is kept, with core's „Közzététel” wording overridden and its back link on the event's „Levelek”
 * page. The post type has no menu item (`show_in_menu` is false). The type and the event meta are named here as
 * strings: they belong to the Mail module, whose internals Admin does not reach. [UNAPPROVED N923, N924, N925, N928,
 * N929, N930]
 */
final class TemplateEditor
{
    private const POST_TYPE = 'kalaka_mail';
    private const META_EVENT_ID = 'kalaka_event_id';

    /**
     * `load-edit.php` and `load-post-new.php`: the native list and the native „new” screen go to the event's „Levelek”
     * page: the event the visitor came from (the referring page), else the site's „Levelezés” page.
     */
    public static function redirectNativeScreens(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read
        $type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        if ($type !== self::POST_TYPE) {
            return;
        }
        $referer = wp_get_referer();
        $query = [];
        parse_str((string) wp_parse_url($referer === false ? '' : $referer, PHP_URL_QUERY), $query);
        $page = is_string($query['page'] ?? null) ? $query['page'] : '';
        $found = preg_match('/^kalaka-event-([1-9][0-9]*)-(?:mail|registrations)$/', $page, $m) === 1;
        $event = $found ? (int) $m[1] : null;
        // The editor's „Lomtárba” (and its restore and delete) come with `ids`, the posts moved, and a `post.php`
        // referer, which has no `page`: the event is then the post's own `kalaka_event_id`, so the admin lands back
        // on that event's „Levelek” page, not the site-wide „Levelezés”. [UNAPPROVED N930]
        if ($event === null) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read
            $ids = isset($_GET['ids']) ? sanitize_text_field(wp_unslash($_GET['ids'])) : '';
            foreach (explode(',', $ids) as $id) {
                $postId = (int) trim($id);
                if ($postId <= 0) {
                    continue;
                }
                $meta = get_post_meta($postId, self::META_EVENT_ID, true);
                if (is_scalar($meta) && preg_match('/\A[1-9][0-9]*\z/', (string) $meta) === 1) {
                    $event = (int) $meta;
                    break;
                }
            }
        }
        wp_safe_redirect(MailPages::url($event));
        exit;
    }

    /** `enqueue_block_editor_assets`: on the template's editor only. */
    public static function editorAssets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->post_type !== self::POST_TYPE || !wp_script_is('wp-edit-post', 'registered')) {
            return;
        }
        $id = (int) get_the_ID();
        $meta = $id > 0 ? get_post_meta($id, self::META_EVENT_ID, true) : '';
        $event = is_scalar($meta) && preg_match('/\A[1-9][0-9]*\z/', (string) $meta) === 1 ? (int) $meta : null;
        $data = wp_json_encode([
            'words' => Texts::EDITOR_WORDS,
            'back' => MailPages::url($event),
            'label' => $event === null ? Texts::EDITOR_BACK_SITE : Texts::EDITOR_BACK,
        ]);
        $script = <<<'JS'
( function ( wp, data ) {
	var words = {};
	Object.keys( data.words ).forEach( function ( key ) {
		words[ key ] = [ data.words[ key ] ];
	} );
	wp.i18n.setLocaleData( words, 'default' );
	var slot = wp.editPost && wp.editPost.__experimentalMainDashboardButton;
	if ( ! slot || ! wp.plugins ) {
		return;
	}
	var el = wp.element.createElement;
	wp.plugins.registerPlugin( 'kalaka-mail-back', {
		render: function () {
			return el( slot, null, el( wp.components.Button, {
				size: 'compact',
				href: data.back,
				label: data.label,
				showTooltip: true,
				tooltipPosition: 'bottom',
				// A real core icon: `wp.icons` (the `@wordpress/icons` global) does not exist in core, so the button
				// was rendering empty. A dashicon slug renders through the Button's own `Icon`. [UNAPPROVED N928]
				icon: 'arrow-left-alt2'
			} ) );
		}
	} );
}( window.wp, __DATA__ ) );
JS;
        // The sidebar's Állapot row takes its status words from a table the editor builds when its script loads
        // (`postStatusesInfo`, `__( 'Published' )` evaluated once at load), so the words must be in place before
        // `wp-editor` runs: core prints that script's translations, then this, then the script. [UNAPPROVED N1032]
        $published = ['Published' => [Texts::EDITOR_WORDS['Published']]];
        wp_add_inline_script(
            'wp-editor',
            'wp.i18n.setLocaleData( ' . wp_json_encode($published) . ", 'default' );",
            'before'
        );
        // Core prints each script's own translations just before it, so this runs after the editor's script.
        wp_add_inline_script('wp-edit-post', str_replace('__DATA__', (string) $data, $script), 'after');
    }
}
