<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\ElementRole;
use Kalaka\Forms\Api\FieldMarkup;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Portal\Api\RegistrationStatusView;
use Kalaka\Portal\Api\StatusRead;
use Kalaka\Portal\Api\WithdrawOffer;
use Kalaka\Portal\Api\Withdrawal;
use Kalaka\Portal\Api\WithdrawStale;
use Kalaka\Portal\Rest\WithdrawRoute;
use Kalaka\Registrations\Api\RegistrationStatus;

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

    /** The handle `register_block_type` gives the status block's `viewScript` (the withdrawal's Modal, R370). */
    public const DIALOG_SCRIPT = 'kalaka-status-view-script';

    /** While the site is closed it shows the closed site's text, like a form. */
    public static function status(): string
    {
        self::prepare();

        return Boundary::render('status block', static function (): string {
            LoadState::current()->requireOpen();

            $view = (new StatusRead())->forActive();

            return StatusMarkup::status($view, ActiveRegistration::followsBadLink(), self::withdrawOffer($view));
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
     * The registrant's own withdrawal as the status markup needs it: the server's facts only (`can.withdraw`, the
     * refusal that came back, the preview of the confirmation step), nothing decided here. [UNAPPROVED N765 to N779]
     */
    private static function withdrawOffer(?RegistrationStatusView $view): ?WithdrawOffer
    {
        if ($view === null) {
            return null;
        }
        $canWithdraw = $view->can->withdraw;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a plain GET that only opens the step
        $asked = isset($_GET['kalaka_withdraw']) ? sanitize_key(wp_unslash($_GET['kalaka_withdraw'])) : '';
        $confirming = $canWithdraw && $asked === 'confirm';
        $paid = null;
        $owedAfter = null;
        $previewFailed = false;
        // The Modal opens with no request, so the preview is taken whenever the button shows (N1333).
        if ($canWithdraw) {
            try {
                $preview = (new Withdrawal())->run(ActiveRegistration::current(), $view->key->code, true);
                $currency = (new Events())->find($view->key->eventId)?->currency;
                $amounts = is_string($preview['paid'] ?? null) && is_string($preview['owed_after'] ?? null);
                if ($amounts && $currency !== null) {
                    $paid = Money::of($preview['paid'], $currency);
                    $owedAfter = Money::of($preview['owed_after'], $currency);
                } else {
                    $previewFailed = $view->status === RegistrationStatus::Registered;
                }
            } catch (WithdrawStale) {
                // The registration is no longer the active one: no offer at all.
                return null;
            } catch (\Throwable) {
                $previewFailed = $view->status === RegistrationStatus::Registered;
            }
        }
        if ($canWithdraw) {
            self::enqueueDialog();
        }
        $buttonClass = '';
        $attribute = FieldMarkup::classAttribute(ElementRole::SubmitButton, 'wp-element-button');
        if (preg_match('/class="([^"]*)"/', $attribute, $found) === 1) {
            $buttonClass = html_entity_decode($found[1]);
        }
        $returnUrl = get_permalink();

        return new WithdrawOffer(
            $canWithdraw,
            WithdrawRoute::url(),
            $returnUrl !== false ? $returnUrl : home_url('/'),
            $view->key->code,
            $buttonClass,
            null, // the adapter's error colour: `ThemeStyle::errorColor()` once #252 is on main (not in Forms\Api here)
            $confirming,
            $view->withdrawRefusal,
            $paid,
            $owedAfter,
            $previewFailed,
            $view->status === RegistrationStatus::Waitlisted,
        );
    }

    /**
     * The script that opens the withdrawal's confirmation in a WordPress Modal (R370), and core's component styles
     * it needs on the page. A block's `viewScript` is enqueued only for the block, so the shortcode's render
     * enqueues it here; without `build/` it is not registered and the no-script way is the only way (N1334).
     */
    private static function enqueueDialog(): void
    {
        if (!wp_script_is(self::DIALOG_SCRIPT, 'registered')) {
            return;
        }
        wp_enqueue_script(self::DIALOG_SCRIPT);
        wp_enqueue_style('wp-components');
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
