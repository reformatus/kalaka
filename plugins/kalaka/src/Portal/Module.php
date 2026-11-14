<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\FormPage;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\UseCase\Runner;

final class Module extends AbstractModule
{
    public function id(): string
    {
        return 'portal';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function boot(): void
    {
        // Before any output or third-party asset, so the secret link's token never reaches a `Referer`.
        add_action('init', [$this, 'resolveActiveRegistration'], 0);
        add_action('wp_logout', [$this, 'logout']);
        add_action('init', [$this, 'register']);
        add_action('template_redirect', [$this, 'preventCaching']);
        add_shortcode(ParticipantBlocks::STATUS_SHORTCODE, [ParticipantBlocks::class, 'status']);
        add_shortcode(ParticipantBlocks::TRANSFER_SHORTCODE, [ParticipantBlocks::class, 'transfer']);
    }

    /** @internal the `init` callback (priority 0): the secret link and the active-registration cookie */
    public function resolveActiveRegistration(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        Boundary::background('active registration', static function (): void {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a secret link is followed from a mail; no form
            // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- verified as a whole by SecretLinks
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
            $url = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
            // While the load failed there may be no runner; the handler then reads no time (it only drops a token).
            $now = LoadState::current()->isOpen()
                ? Runner::current()->clock()->now()
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            (new LinkHandler())->handle(wp_unslash($_GET), wp_unslash($_COOKIE), $method, $url, $now);
            // phpcs:enable
        });
    }

    /** @internal the `wp_logout` callback */
    public function logout(): void
    {
        (new LinkHandler())->logout();
    }

    /** @internal the `init` callback: the stylesheet and the two blocks, from `build/` */
    public function register(): void
    {
        // Plain CSS, which the build does not copy, so it is served from `src/` (the zip carries `src/`).
        wp_register_style(
            ParticipantBlocks::STYLE,
            plugins_url('src/Portal/portal.css', KALAKA_FILE),
            [],
            KALAKA_VERSION
        );
        $built = dirname(KALAKA_FILE) . '/build/Portal/Blocks/';
        $renders = [
            'status' => [ParticipantBlocks::STATUS_BLOCK, [ParticipantBlocks::class, 'status']],
            'transfer' => [ParticipantBlocks::TRANSFER_BLOCK, [ParticipantBlocks::class, 'transfer']],
        ];
        foreach ($renders as $folder => [$name, $render]) {
            if (is_file($built . $folder . '/block.json')) {
                register_block_type($built . $folder);
            } else {
                // Without `build/` a page that places the block still shows it, from its server render only.
                register_block_type($name, ['render_callback' => static fn (): string => $render()]);
            }
        }
    }

    /**
     * @internal the `template_redirect` callback: a singular page that places a participant block is never
     * cached, and its stylesheet loads in the head.
     */
    public function preventCaching(): void
    {
        Boundary::background('participant block caching', static function (): void {
            $post = is_singular() ? get_queried_object() : null;
            if ($post instanceof \WP_Post && ParticipantBlocks::placedIn($post->post_content)) {
                FormPage::preventCaching();
                wp_enqueue_style(ParticipantBlocks::STYLE);
            }
        });
    }
}
