<?php

declare(strict_types=1);

namespace Kalaka\Admin\Mail;

/**
 * The mail templates (`kalaka_mail` posts) in WordPress's own screens: they leave the native post list, and the
 * editor (`post.php`) is kept, with core's „Közzététel” wording overridden and its back link on the event's „Levelek”
 * page. The post type has no menu item (`show_in_menu` is false). The type and the event meta are named here as
 * strings: they belong to the Mail module, whose internals Admin does not reach. [UNAPPROVED N923, N924, N925]
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
            'label' => Texts::EDITOR_BACK,
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
        // Core prints each script's own translations just before it, so this runs after the editor's script.
        wp_add_inline_script('wp-edit-post', str_replace('__DATA__', (string) $data, $script), 'after');
    }
}
