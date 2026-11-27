<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\Api\StatusRead;

/**
 * The participant blocks' renders, shared by each block and its shortcode: the status block
 * `kalaka/status` (`[kalaka_status]`), the transfer block `kalaka/transfer` (`[kalaka_transfer]`) and the
 * leave block `kalaka/leave` (`[kalaka_leave]`). Each renders inside its own error boundary, never cached,
 * with the plugin's structural stylesheet.
 */
final class ParticipantBlocks
{
    public const STATUS_BLOCK = 'kalaka/status';

    public const TRANSFER_BLOCK = 'kalaka/transfer';

    public const LEAVE_BLOCK = 'kalaka/leave';

    public const STATUS_SHORTCODE = 'kalaka_status';

    public const TRANSFER_SHORTCODE = 'kalaka_transfer';

    public const LEAVE_SHORTCODE = 'kalaka_leave';

    /** The button's label: „Kijelentkezés”. [UNAPPROVED T1180] */
    public const LEAVE_LABEL = 'Kijelentkezés';

    /** The handle of `portal.css`, named by both blocks' `style`. */
    public const STYLE = 'kalaka-portal';

    /** While the site is closed it shows the closed site's text, like a form. */
    public static function status(): string
    {
        self::prepare();

        return Boundary::render('status block', static function (): string {
            LoadState::current()->requireOpen();

            return StatusMarkup::status((new StatusRead())->forActive(), ActiveRegistration::followsBadLink());
        });
    }

    /** Nothing while the site is closed: the status block says so. */
    public static function transfer(): string
    {
        self::prepare();
        if (!LoadState::current()->isOpen()) {
            return '';
        }

        return Boundary::render('transfer block', static function (): string {
            return StatusMarkup::transfer((new StatusRead())->forActive(), ActiveRegistration::followsBadLink());
        });
    }

    /**
     * The leave button: one form that posts the page's own address to `kalaka-portal/v1/leave`. Nothing
     * without an active registration and without a logged-in account, nor while the site is closed.
     */
    public static function leave(): string
    {
        FormCaching::preventCaching();
        if (!LoadState::current()->isOpen()) {
            return '';
        }

        return Boundary::render('leave block', static function (): string {
            if (ActiveRegistration::current() === null && !is_user_logged_in()) {
                return '';
            }

            return StatusMarkup::leave(rest_url(LeaveRoute::NAMESPACE . LeaveRoute::ROUTE), self::pageAddress());
        });
    }

    /** The address of the page being rendered; the home page when the request has none (a test, WP-CLI). */
    private static function pageAddress(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- escaped where it is built; the route validates it
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $home = wp_parse_url(home_url('/'));
        if ($uri === '' || !str_starts_with($uri, '/') || !is_array($home) || !isset($home['scheme'], $home['host'])) {
            return home_url('/');
        }

        return $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '') . $uri;
    }

    /**
     * Whether the content places a participant block: a block, or a shortcode anywhere (also nested in a
     * page builder's own shortcodes), matched by its tag whether or not it is registered yet.
     */
    public static function placedIn(string $content): bool
    {
        return has_block(self::STATUS_BLOCK, $content) || has_block(self::TRANSFER_BLOCK, $content)
            || has_block(self::LEAVE_BLOCK, $content)
            || str_contains($content, '[' . self::LEAVE_SHORTCODE)
            || str_contains($content, '[' . self::STATUS_SHORTCODE)
            || str_contains($content, '[' . self::TRANSFER_SHORTCODE);
    }

    /**
     * A page holding a participant block is never cached (registrations-and-identity.md, "Access"): again
     * here, for a block placed where the `template_redirect` check cannot see it.
     */
    private static function prepare(): void
    {
        FormCaching::preventCaching();
        wp_enqueue_style(self::STYLE);
    }
}
