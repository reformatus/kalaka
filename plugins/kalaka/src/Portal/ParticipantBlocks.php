<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\FormPage;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\Api\StatusRead;

/**
 * The participant blocks' renders, shared by each block and its shortcode: the status block
 * `kalaka/status` (`[kalaka_status]`) and the transfer block `kalaka/transfer` (`[kalaka_transfer]`).
 * Each renders inside its own error boundary, never cached, with the plugin's structural stylesheet.
 */
final class ParticipantBlocks
{
    public const STATUS_BLOCK = 'kalaka/status';

    public const TRANSFER_BLOCK = 'kalaka/transfer';

    public const STATUS_SHORTCODE = 'kalaka_status';

    public const TRANSFER_SHORTCODE = 'kalaka_transfer';

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
     * Whether the content places a participant block: a block, or a shortcode anywhere (also nested in a
     * page builder's own shortcodes), matched by its tag whether or not it is registered yet.
     */
    public static function placedIn(string $content): bool
    {
        return has_block(self::STATUS_BLOCK, $content) || has_block(self::TRANSFER_BLOCK, $content)
            || str_contains($content, '[' . self::STATUS_SHORTCODE)
            || str_contains($content, '[' . self::TRANSFER_SHORTCODE);
    }

    /**
     * A page holding a participant block is never cached (registrations-and-identity.md, "Access"): again
     * here, for a block placed where the `template_redirect` check cannot see it.
     */
    private static function prepare(): void
    {
        FormPage::preventCaching();
        wp_enqueue_style(self::STYLE);
    }
}
