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
     * inputs (`#eee`, their text `#666`, a darker grey on hover), with no border or radius; a filled control
     * (the ranking's +/×) takes the same greys. Divi styles its own inputs and buttons (`et_pb_button`)
     * itself; this reaches only what Kaláka draws.
     */
    private const BASE = '.et_pb_contact_form{--kalaka-control-background:#eee;--kalaka-control-color:#666;'
        . '--kalaka-control-hover-background:#ddd;--kalaka-border-color:transparent;--kalaka-radius:0;'
        . '--kalaka-button-background:#eee;--kalaka-button-color:#666;--kalaka-button-hover-background:#ddd}';

    /**
     * What the variables cannot reach in Divi:
     * - the ranking's ↑/↓, outlined beside the filled +/× (owner, 2026-11-18): core's base button would take
     *   the input grey as its background and a transparent border, so they get no fill, a 2-pixel border
     *   in Divi's input grey (Divi's buttons' border width) and its text grey, and the grey fill under a
     *   hovering pointer; none on one that does nothing now;
     * - a dark focus ring on the ranking's controls, which Divi's `:focus { outline: 0 }` removes
     *   (unlayered, so it wins over Divi's rule);
     * - the row gap; the handle in Divi's input text grey (`#999`); thin lines between ranked rows;
     * - the empty rows' dashed places, which Divi's reset (`li { border: 0; margin: 0 }`, unlayered) and the
     *   transparent border colour would hide: the base's faded text colour, not the variable;
     * - the other options without Divi's bullets and indent, their text in line with the ranked ones'
     *   (`:not(#kalaka)` weighs like an id, to outweigh Divi's `#left-area ul`);
     * - a white, lifted row while it is dragged (owner, S45 round 2, approved as built).
     */
    private const RANKING = '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]>button'
        . '{background:transparent;color:var(--kalaka-control-color);border:2px solid #eee;'
        . 'border-radius:var(--kalaka-radius)}'
        . '@media (hover:hover){.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]'
        . '>button:not([aria-disabled=true]):hover{background:var(--kalaka-control-background)}}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]>button:focus-visible,'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-controls]>input[data-kalaka-toggle]:focus-visible'
        . '{outline:2px solid #333;outline-offset:2px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-row]{gap:4px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-handle]{color:#999;font-size:18px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-empty]>li'
        . '{border:1px dashed color-mix(in srgb,currentColor 35%,transparent);margin-bottom:4px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-ranked] [data-kalaka-row]'
        . '{border-bottom:1px solid #eee}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-unranked]:not(#kalaka)'
        . '{list-style:none;padding-left:calc(1.5em + 48px)}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-unranked] [data-kalaka-row]'
        . '{margin-bottom:4px}'
        . '.et_pb_contact_form [data-kalaka-ranking] [data-kalaka-dragging] [data-kalaka-row]'
        . '{background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15)}';

    /**
     * The registrant's money table (`kalaka/status`) in Divi's look: Divi gives a table cell `td` a `#eee` line on
     * its top edge and a row header `th` none, so the first column gets the same line and the rows read as one
     * (every row but the ones with core's own darker separator, `kalaka-rule`). Core keeps the structure (the
     * columns, the popover) and the emphasis (`em`, `strong`); nothing else of the table is styled here.
     */
    public const MONEY_TABLE = '.kalaka-status table.kalaka-money tr:not(.kalaka-rule)>th{border-top:1px solid #eee}';

    /**
     * Divi's button icon (an arrow glyph from its icon font, shown on hover) is a pseudo-element's `content`,
     * which a screen reader announces ("Küldés 5"). The same `content` Divi sets, with an empty text
     * alternative after the slash: the glyph looks the same, speech skips it. The plain declaration first, so
     * a browser without the alternative syntax keeps it as before. Kaláka's own buttons only: the form's
     * (back, next, send) and the status block's card payment button.
     */
    public const BUTTON_ICON = '.et_pb_contact_form[data-wp-interactive="kalaka/form"] .et_pb_button:after,'
        . '.kalaka-pay-card .et_pb_button:after{content:"\35";content:"\35" / ""}';

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
     * Loads Divi's form styles where a form or the status block appears, through Divi's own mechanism: its
     * dynamic assets load a module's styles only on pages that use the module, unless
     * `et_required_module_assets` asks for them. The status block's card payment button wears the form's
     * button class, so it needs the same styles. Divi (Dynamic and Critical CSS, both on by default) prints the
     * styles of the modules above the fold inline and defers the rest with a script (no stylesheet without
     * JavaScript), and a module added through `et_required_module_assets` is never above the fold by its
     * own detection: so the same module is also named above the fold, after Divi's own list (priority 11).
     */
    public function boot(): void
    {
        add_filter('et_required_module_assets', [$this, 'requiredAssets'], 10, 2);
        add_filter('et_dynamic_assets_modules_atf', [$this, 'aboveTheFoldAssets'], 11, 2);
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
            && (self::holdsForm($content) || self::holdsMoneyTable($content))
            && !in_array(self::DIVI_ASSETS, $assets, true)
        ) {
            $assets[] = self::DIVI_ASSETS;
        }

        return $assets;
    }

    /**
     * @internal the `et_dynamic_assets_modules_atf` filter (Divi 4.10 and later): the modules whose styles
     * go inline into the page's critical CSS. Divi's own callback replaces the list (priority 10), so this
     * one runs after it and appends.
     * @param mixed $modules
     * @param mixed $content
     * @return mixed
     */
    public function aboveTheFoldAssets($modules, $content = '')
    {
        return $this->requiredAssets($modules, $content);
    }

    /** @internal the `wp_enqueue_scripts` action */
    public function enqueue(): void
    {
        $post = get_post();
        if (!$this->isDivi() || !is_singular() || $post === null) {
            return;
        }
        $css = (self::holdsForm($post->post_content)
            ? self::DIV_FIELDS . self::IPHONE_CONTROLS . self::BASE . self::RANKING : '')
            . (self::holdsMoneyTable($post->post_content) ? self::MONEY_TABLE : '');
        if ($css !== '') {
            $css .= self::BUTTON_ICON;
        }
        if ($css === '') {
            return;
        }
        wp_register_style('kalaka-divi4', false, [], KALAKA_VERSION);
        wp_add_inline_style('kalaka-divi4', $css);
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

    /** Whether the content places the registrant's status block, which holds the money table: block or shortcode. */
    public static function holdsMoneyTable(string $content): bool
    {
        return has_block('kalaka/status', $content) || str_contains($content, '[kalaka_status');
    }

    /** Whether the active theme is Divi 4 (the adapter's own rule). */
    private function isDivi(): bool
    {
        return $this->adapter->applies(get_template());
    }
}
