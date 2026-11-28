<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Events\Api\Events;
use Kalaka\Forms\Api\FormCaching;
use Kalaka\Forms\Api\ThemeStyle;
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

    /** The button's label: „Kijelentkezés”. */
    public const LEAVE_LABEL = 'Kijelentkezés';

    /** The handle of `portal.css`, named by both blocks' `style`. */
    public const STYLE = 'kalaka-portal';

    /**
     * The handle of the withdrawal's Modal script (R370, `Blocks/status/view.js`), which `Portal\Module` registers
     * and `enqueueDialog()` enqueues only where a withdraw button shows. No `block.json` names it.
     */
    public const DIALOG_SCRIPT = 'kalaka-portal-withdraw';

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
        $retained = null;
        $surplus = null;
        $previewFailed = false;
        // The Modal opens with no request, so the preview is taken whenever the button shows (N1333).
        if ($canWithdraw) {
            try {
                $preview = (new Withdrawal())->run(ActiveRegistration::current(), $view->key->code, true);
                $currency = (new Events())->find($view->key->eventId)?->currency;
                $amounts = is_string($preview['paid'] ?? null) && is_string($preview['retained'] ?? null)
                    && is_string($preview['surplus'] ?? null);
                if ($amounts && $currency !== null) {
                    $paid = Money::of($preview['paid'], $currency);
                    $retained = Money::of($preview['retained'], $currency);
                    $surplus = Money::of($preview['surplus'], $currency);
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
        $returnUrl = get_permalink();

        return new WithdrawOffer(
            $canWithdraw,
            WithdrawRoute::url(),
            $returnUrl !== false ? $returnUrl : home_url('/'),
            $view->key->code,
            ThemeStyle::buttonClass(),
            ThemeStyle::errorColor(),
            $confirming,
            $view->withdrawRefusal,
            $paid,
            $retained,
            $surplus,
            $previewFailed,
            $view->status === RegistrationStatus::Waitlisted,
            Withdrawal::nonceValue($view->key->code),
        );
    }

    /**
     * The script that opens the withdrawal's confirmation in a WordPress Modal (R370), and core's component styles
     * it needs on the page, for the block and the shortcode alike and only while the withdraw button shows;
     * without `build/` it is not registered and the no-script way is the only way (N1334).
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
