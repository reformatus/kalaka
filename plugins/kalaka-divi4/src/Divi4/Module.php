<?php

declare(strict_types=1);

namespace Kalaka\Divi4;

use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Contributions;

final class Module extends AbstractModule
{
    /** The Divi module whose styles Kaláka's forms borrow: the contact form's (with its fields and buttons). */
    private const DIVI_ASSETS = 'et_pb_contact_form';

    /**
     * Divi pads a `p.et_pb_contact_field` only; a choice or a consent is a `div` (structural only: the
     * same spacing, no colours).
     */
    private const DIV_FIELDS = 'div.kalaka-field.et_pb_contact_field{padding:0 0 0 3%;margin-bottom:3%}';

    /**
     * Divi marks an iPhone's `body` with `iphone` and gives `.iphone .et_pb_contact_form input`
     * `-webkit-appearance: initial`, which leaves a native checkbox or radio button 0×0: invisible and
     * hard to tick. Kaláka's form carries `et_pb_contact_form`, so the browser's own controls come back
     * for Kaláka's fields only (functional, no look of our own). The ranking's +/× toggle is a checkbox
     * Kaláka draws itself (`appearance: none`), so it is left out.
     */
    public const IPHONE_CONTROLS = '.iphone .et_pb_contact_form .kalaka-field'
        . ' input[type=checkbox]:not([data-kalaka-toggle]),'
        . '.iphone .et_pb_contact_form .kalaka-field input[type=radio]{-webkit-appearance:auto;appearance:auto}';

    /**
     * Kaláka's neutral base (core's `kalaka` cascade layer) mapped onto Divi's form look: its documented
     * `--kalaka-*` variables, set on Divi's contact form, give Kaláka's own controls the grey of Divi's form
     * inputs (`#eee`, their text `#666`, a darker grey on hover), with no border or radius. Divi styles its
     * own inputs and buttons (`et_pb_button`) itself; this reaches only what Kaláka draws.
     */
    private const BASE = '.et_pb_contact_form{--kalaka-control-background:#eee;--kalaka-control-color:#666;'
        . '--kalaka-control-hover-background:#ddd;--kalaka-border-color:transparent;--kalaka-radius:0}';

    /**
     * What the variables cannot reach in Divi: a dark focus ring on the ranking's controls, which Divi's
     * `:focus { outline: 0 }` removes (unlayered, so it wins over Divi's rule); the handle in Divi's input
     * text grey (`#999`); thin lines between ranked rows; the other options without Divi's bullets and
     * indent, their text in line with the ranked ones' (`:not(#kalaka)` weighs like an id, to outweigh
     * Divi's `#left-area ul`); a white, lifted row while it is dragged (owner, S45 round 2, approved as built).
     */
    private const RANKING = '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]>button:focus-visible,'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]>input[data-kalaka-toggle]:focus-visible'
        . '{outline:2px solid #333;outline-offset:2px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-row]{gap:4px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-handle]{color:#999;font-size:18px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-ranked] [data-kalaka-row]'
        . '{border-bottom:1px solid #eee}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-unranked]:not(#kalaka)'
        . '{list-style:none;padding-left:calc(1.5em + 48px)}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-unranked] [data-kalaka-row]'
        . '{margin-bottom:4px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-dragging] [data-kalaka-row]'
        . '{background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15)}';

    /** Constructing hooks nothing (extensions are collected again by the Playground smoke test). */
    public function __construct(private readonly DiviThemeAdapter $adapter = new DiviThemeAdapter())
    {
    }

    public function id(): string
    {
        return 'divi4';
    }

    /** During 0.x a minor bump of VERSION updates this range in the same change. */
    public function requiresApi(): string
    {
        return '>=0.1 <0.2';
    }

    public function contribute(Contributions $to): void
    {
        $to->themeAdapter($this->adapter);
    }

    /**
     * Loads Divi's form styles where a form appears, through Divi's own mechanism: its dynamic assets load
     * a module's styles only on pages that use the module, unless `et_required_module_assets` asks for them.
     */
    public function boot(): void
    {
        add_filter('et_required_module_assets', [$this, 'requiredAssets'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * @internal the `et_required_module_assets` filter
     * @param mixed $assets
     * @param mixed $content
     * @return mixed
     */
    public function requiredAssets($assets, $content = '')
    {
        if (
            is_array($assets) && is_string($content) && $this->isDivi()
            && self::holdsForm($content) && !in_array(self::DIVI_ASSETS, $assets, true)
        ) {
            $assets[] = self::DIVI_ASSETS;
        }

        return $assets;
    }

    /** @internal the `wp_enqueue_scripts` action */
    public function enqueue(): void
    {
        $post = get_post();
        if (!$this->isDivi() || !is_singular() || $post === null || !self::holdsForm($post->post_content)) {
            return;
        }
        wp_register_style('kalaka-divi4', false, [], KALAKA_VERSION);
        wp_add_inline_style('kalaka-divi4', self::DIV_FIELDS . self::IPHONE_CONTROLS . self::BASE . self::RANKING);
        wp_enqueue_style('kalaka-divi4');
    }

    /**
     * Whether the content places a form: the embed block, or the shortcode anywhere, also nested in the
     * Divi Builder's own shortcodes.
     */
    public static function holdsForm(string $content): bool
    {
        return has_block('kalaka/form', $content) || str_contains($content, '[kalaka_form');
    }

    /** Whether the active theme is Divi 4 (the adapter's own rule). */
    private function isDivi(): bool
    {
        return $this->adapter->applies(get_template());
    }
}
