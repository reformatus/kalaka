<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Module\AbstractModule;
use Kalaka\Foundation\Api\Module\Schema;
use Kalaka\Foundation\Api\UseCase\Runner;

final class Module extends AbstractModule
{
    /**
     * The parked PWA scope: one row per registration and device, keyed by (event ID, code, endpoint hash),
     * so a device subscribes once per registration. `endpoint` is the push service's URL, too long for a
     * unique index, so `endpoint_hash` (the SHA-256 hex of it) is the key part; both are `utf8mb4_nopad_bin`,
     * compared exactly. `key_p256dh` and `key_auth` are the subscription's keys: secret columns, which a
     * repository over this table lists in `secretColumns()`, so the audit masks them like a registration's
     * `secret`. `KEY endpoint_hash` serves the delete by a push service's rejection of one endpoint. No
     * last-seen column: a dead subscription is found from that rejection. Nothing writes the table yet. A row
     * is deleted on unsubscribe, on the registration's withdrawal and when the event ends.
     */
    private const PUSH_SUBSCRIPTIONS = <<<'SQL'
CREATE TABLE {prefix}kalaka_push_subscriptions (
  event_id bigint(20) unsigned NOT NULL,
  code varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci NOT NULL,
  endpoint_hash varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  endpoint longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  key_p256dh varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  key_auth varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin NOT NULL,
  created_at datetime(6) NOT NULL,
  PRIMARY KEY  (event_id,code,endpoint_hash),
  KEY endpoint_hash (endpoint_hash)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci
SQL;

    public function id(): string
    {
        return 'portal';
    }

    public function requiresApi(): string
    {
        return '>=' . KALAKA_VERSION;
    }

    public function schema(): Schema
    {
        return new Schema(1, [self::PUSH_SUBSCRIPTIONS]);
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
                FormCaching::preventCaching();
                wp_enqueue_style(ParticipantBlocks::STYLE);
            }
        });
    }
}
