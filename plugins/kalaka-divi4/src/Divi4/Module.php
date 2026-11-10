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
        wp_add_inline_style('kalaka-divi4', self::DIV_FIELDS);
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
