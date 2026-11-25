<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\DateText;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Money\MoneyText;
use Kalaka\Portal\Api\Availability;
use Kalaka\Portal\Api\PaymentView;
use Kalaka\Portal\Api\RegistrationStatusView;
use Kalaka\Portal\Api\WithdrawOffer;
use Kalaka\Registrations\Api\RegistrationStatus;

/**
 * The markup of the two participant blocks, from the frozen status-block spec (owner-accepted 2026-11-13,
 * round 6): its structure, texts, bold policy and box colours, with WordPress's own preset classes
 * (`has-{slug}-background-color`, `has-medium-font-size`) and no look of its own. The text reads correctly
 * without the presets and without `portal.css`.
 */
final class StatusMarkup
{
    public const NO_ACTIVE = 'A jelentkezésed állapotának megnézéséhez nyisd meg a jelentkezés után kapott'
        . ' e-mailben lévő linket.';

    /** SB-11: the active registration's event is not open (unpublished, a draft or archived). */
    public const EVENT_CLOSED = 'A jelentkezésed állapota jelenleg nem érhető el.';

    public const BAD_LINK = 'Ez a link nem érvényes. Nyisd meg a legutóbb kapott e-mailben lévő linket.';

    /** [UNAPPROVED T765] The button that starts the registrant's own withdrawal. */
    public const WITHDRAW_BUTTON = 'Jelentkezés lemondása';

    /** [UNAPPROVED T766] The confirmation step's question (a heading, N779). */
    public const WITHDRAW_QUESTION = 'Biztosan lemondod a jelentkezésedet?';

    /** [UNAPPROVED T767] The confirming button. */
    public const WITHDRAW_CONFIRM = 'Lemondom a jelentkezést';

    /** [UNAPPROVED T768] Leaves the confirmation step without acting ("Mégse", the app's own cancel word). */
    public const WITHDRAW_CANCEL = 'Mégse';

    /** [UNAPPROVED T769] The money line, first part: the preview's `paid`. */
    public const WITHDRAW_PAID = 'Befizetve:';

    /** [UNAPPROVED T769] The money line, second part: the preview's `owed_after`, when it is zero or above. */
    public const WITHDRAW_OWED_AFTER = 'A lemondás után még fizetendő:';

    /**
     * [UNAPPROVED T775] The money line, second part when the preview's `owed_after` is below zero (a surplus):
     * "Túlfizetés" is the money table's approved row label.
     */
    public const WITHDRAW_SURPLUS = 'Túlfizetés:';

    /** [UNAPPROVED T772] Stands in the confirmation of a registered one when the preview gave nothing. */
    public const WITHDRAW_NO_MONEY = 'A befizetett összeget most nem tudjuk megmutatni.';

    /** [UNAPPROVED T770] `kalaka_withdraw_unavailable` (and, T774, `kalaka_status_act_refused`): shown only where it is true, N771. */
    public const WITHDRAW_UNAVAILABLE = 'Ezt a jelentkezést már nem tudod lemondani. Töltsd újra az oldalt.';

    /** [UNAPPROVED T771] `kalaka_withdraw_stale`: the page the form came from is out of date. */
    public const WITHDRAW_STALE = 'Az oldal időközben megváltozott. Nézd meg a jelentkezés mostani állapotát.';

    /** [UNAPPROVED T773] `kalaka_version_conflict`, in the registrant's wording (the lektor's alternative). */
    public const WITHDRAW_VERSION_CONFLICT = 'A jelentkezésed időközben megváltozott. Töltsd újra az oldalt, és próbáld újra.';

    private const MINUS = "\u{2212}";

    /** The status block "Jelentkezésem állapota". */
    public static function status(?RegistrationStatusView $view, bool $badLink, ?WithdrawOffer $withdraw = null): string
    {
        $html = '<section class="kalaka-status"><h2>Jelentkezésem állapota</h2>';
        if ($badLink) {
            return $html . '<p>' . esc_html(self::BAD_LINK) . '</p></section>';
        }
        if ($view === null) {
            return $html . '<p>' . esc_html(self::NO_ACTIVE) . '</p></section>';
        }
        // SB-11: a closed or archived event shows nothing of the registration.
        if ($view->availability !== Availability::Open) {
            return $html . '<p>' . esc_html(self::EVENT_CLOSED) . '</p></section>';
        }

        $html .= '<p>Azonosító: <span class="kalaka-code has-medium-font-size">' . esc_html($view->key->code)
            . '</span></p>';

        switch ($view->status) {
            case RegistrationStatus::Waitlisted:
                return $html . self::box(
                    '<p><strong>Várólistán vagy.</strong> Amíg nem kerülsz be, nem kell fizetned.</p>',
                    'very-light-gray'
                ) . self::withdraw($withdraw) . '</section>';
            case RegistrationStatus::Withdrawn:
                return $html . '<p><strong>Lemondtad ezt a jelentkezést.</strong></p>'
                    . self::withdraw($withdraw) . '</section>';
            case RegistrationStatus::Removed:
                return $html . '<p><strong>A szervezők törölték ezt a jelentkezést.</strong></p>'
                    . self::withdraw($withdraw) . '</section>';
            case RegistrationStatus::Registered:
                break;
        }

        $payment = $view->payment;
        if ($payment === null) {
            return $html . self::withdraw($withdraw) . '</section>';
        }
        $confirmed = $view->confirmation !== null && $view->confirmation->confirmed;
        if ($payment->awaitsAnswer) {
            $state = self::box(
                '<p><strong>Elbírálás alatt.</strong> Kérjük, ne fizess, amíg e-mailben meg nem írjuk a végleges'
                . ' összeget.</p>',
                'pale-pink'
            );
        } elseif ($payment->overdue) {
            $state = self::box('<p><strong>Lejárt a fizetési határidő.</strong></p>', 'pale-pink');
        } elseif ($confirmed) {
            // SQ-8: the text on handling an overpayment is the stakeholders'; until then the table's
            // "Túlfizetés" row carries the amount and the box says nothing more.
            $state = self::box('<p><strong>Visszaigazolva.</strong></p>', 'light-green-cyan');
        } else {
            $state = '<p><strong>Fizetésre vár.</strong></p>';
        }

        return $html . $state . '<h3>Pénzügyek</h3>' . self::payment($payment) . self::withdraw($withdraw)
            . '</section>';
    }

    /**
     * The transfer block "Átutalási adatok": nothing at all unless the view carries transfer details (SB-4).
     * It renders no heading.
     */
    public static function transfer(?RegistrationStatusView $view, bool $badLink): string
    {
        $transfer = $badLink || $view === null ? null : $view->transfer;
        if ($transfer === null) {
            return '';
        }

        return '<section class="kalaka-transfer"><p>Az átutaláshoz ezeket az adatokat add meg:</p>'
            . '<table class="kalaka-transfer-details"><tbody>'
            . self::transferRow('Kedvezményezett', $transfer->beneficiary)
            . self::transferRow('Számlaszám', $transfer->accountNumber)
            . self::transferRow('Összeg', MoneyText::of($transfer->amount))
            . self::transferRow('Közlemény', $transfer->reference)
            . '</tbody></table></section>';
    }

    /**
     * The payment table (lines, total, paid, then still due or overpaid), the deadline and the note on
     * hand-recorded payments. Only the amount still due is bold, and only while it is payable now.
     */
    private static function payment(PaymentView $payment): string
    {
        $participant = $payment->participant;
        $html = '<table class="kalaka-money"><tbody>';
        foreach ($payment->lines as $index => $line) {
            $sign = $index === 0 ? '' : ($line->sign < 0 ? self::MINUS : '+');
            $html .= self::moneyRow($line->label, $sign, $line->amount);
        }
        $html .= self::moneyRow('Fizetendő összesen', '', $participant->charged);
        $html .= self::moneyRow('Befizetve', '', $participant->paid, false, 'kalaka-rule');

        $balance = $participant->balance;
        $due = $balance->roundedUpToPayableUnit();
        $payableNow = $due->isPositive() && !$payment->awaitsAnswer;
        if ($balance->isNegative() && !$balance->countsAsZero()) {
            $html .= self::moneyRow('Túlfizetés', '', $balance->negated(), false, 'kalaka-rule');
        } else {
            $shown = $due->isPositive() ? $due : Money::zero($due->currency());
            $class = $payableNow ? 'kalaka-rule kalaka-due' : 'kalaka-rule';
            $html .= self::moneyRow('Még fizetendő', '', $shown, $payableNow, $class);
        }
        $html .= '</tbody></table>';

        if ($payableNow && $payment->paymentDue !== null) {
            $day = $payment->paymentDue->setTimezone(wp_timezone())->modify('-1 day')->format('Y-m-d');
            $html .= '<p class="kalaka-deadline">Fizetési határidő: ' . esc_html(DateText::of($day)) . '</p>';
        }

        return $html . '<p>A befizetéseket kézzel rögzítjük, ezért néhány napba is telhet, mire itt megjelennek.</p>';
    }

    /**
     * The registrant's own withdrawal [UNAPPROVED N765 to N777, N779]: the button while `can.withdraw`;
     * on the page's own confirm request (`?kalaka_withdraw=confirm`) the confirmation step in its place, a plain form
     * that works without script (the post is the confirmed act; Mégse is a link back to the button); a refused post's text, by code.
     * With nothing to show it renders nothing. The money line comes from the preview's values only, never from the
     * page's own rules (N773).
     */
    private static function withdraw(?WithdrawOffer $offer): string
    {
        if ($offer === null) {
            return '';
        }
        $colour = $offer->errorColour ?? '#cc1818';
        $text = $offer->refusal === null ? null : self::withdrawRefusalText($offer->refusal);
        // N771: the "no longer possible" texts are true only where the button is gone.
        if ($offer->canWithdraw && $text === self::WITHDRAW_UNAVAILABLE) {
            $text = null;
        }
        if (!$offer->canWithdraw && $text === null) {
            return '';
        }
        $error = $text === null
            ? ''
            : '<p id="kalaka-withdraw-error" class="kalaka-withdraw-error" role="alert" tabindex="-1" style="'
                . esc_attr('color: var(--kalaka-error-color, #cc1818)') . '">' . esc_html($text) . '</p>';
        $describedBy = $text === null ? '' : ' aria-describedby="kalaka-withdraw-error"';
        $class = $offer->buttonClass === '' ? '' : ' class="' . esc_attr($offer->buttonClass) . '"';
        $open = '<div id="kalaka-withdraw" class="kalaka-withdraw" style="'
            . esc_attr('--kalaka-error-color: ' . $colour) . '">';
        if (!$offer->canWithdraw) {
            return $open . $error . '</div>';
        }
        // A refused post comes back to the error (N779: the fragment moves focus to it, so it is read out).
        $returnUrl = $offer->returnUrl;
        $errorUrl = $returnUrl . '#kalaka-withdraw-error';
        if (!$offer->confirming) {
            return $open . '<form method="get" action="' . esc_url($returnUrl . '#kalaka-withdraw-question') . '">'
                . '<button type="submit" name="kalaka_withdraw" value="confirm"' . $class . $describedBy . '>'
                . esc_html(self::WITHDRAW_BUTTON) . '</button></form>' . $error . '</div>';
        }

        return $open . '<form method="post" action="' . esc_url($offer->actionUrl) . '">'
            . '<h3 id="kalaka-withdraw-question" tabindex="-1">' . esc_html(self::WITHDRAW_QUESTION) . '</h3>'
            . self::withdrawMoney($offer)
            . '<input type="hidden" name="code" value="' . esc_attr($offer->code) . '">'
            . '<input type="hidden" name="return_url" value="' . esc_attr($errorUrl) . '">'
            . '<button type="submit"' . $class . $describedBy . '>' . esc_html(self::WITHDRAW_CONFIRM) . '</button> '
            . '<a href="' . esc_url($returnUrl . '#kalaka-withdraw') . '"' . $class . '>'
            . esc_html(self::WITHDRAW_CANCEL) . '</a></form>' . $error . '</div>';
    }

    /**
     * The confirmation's money line [UNAPPROVED N773]: only what the preview sent. Nothing when nothing was paid and
     * the preview was fine; a stated gap when a registered one's preview gave nothing (no value is made up).
     */
    private static function withdrawMoney(WithdrawOffer $offer): string
    {
        if ($offer->paid === null) {
            return $offer->previewFailed
                ? '<p class="kalaka-withdraw-money">' . esc_html(self::WITHDRAW_NO_MONEY) . '</p>'
                : '';
        }
        if (!$offer->paid->isPositive()) {
            return '';
        }
        $line = self::WITHDRAW_PAID . ' ' . MoneyText::of($offer->paid) . '.';
        if ($offer->owedAfter !== null) {
            $line .= $offer->owedAfter->isNegative()
                ? ' ' . self::WITHDRAW_SURPLUS . ' ' . MoneyText::of($offer->owedAfter->negated()) . '.'
                : ' ' . self::WITHDRAW_OWED_AFTER . ' ' . MoneyText::of($offer->owedAfter) . '.';
        }

        return '<p class="kalaka-withdraw-money">' . esc_html($line) . '</p>';
    }

    /**
     * [UNAPPROVED N771] What a refused withdrawal shows, by the route's code: new texts for the two withdrawal codes,
     * the registrant's wording for a version conflict, the "no longer possible" text for a refused act, and the
     * generic text of `kalaka_system_failure` for any other code (including `kalaka_site_closed`).
     */
    public static function withdrawRefusalText(string $code): string
    {
        return match ($code) {
            'kalaka_withdraw_stale' => self::WITHDRAW_STALE,
            'kalaka_withdraw_unavailable', 'kalaka_status_act_refused' => self::WITHDRAW_UNAVAILABLE,
            'kalaka_version_conflict' => self::WITHDRAW_VERSION_CONFLICT,
            default => (new SystemFailure())->publicMessage(),
        };
    }

    /** A state inside a core group with a default-palette background; the text stays black on it. */
    private static function box(string $inner, string $slug): string
    {
        return '<div class="wp-block-group has-background has-' . $slug . '-background-color has-text-color'
            . ' has-black-color">' . $inner . '</div>';
    }

    private static function moneyRow(
        string $label,
        string $sign,
        Money $amount,
        bool $strong = false,
        string $class = '',
    ): string {
        $text = esc_html(MoneyText::of($amount));

        return '<tr' . ($class === '' ? '' : ' class="' . esc_attr($class) . '"') . '><th scope="row">'
            . esc_html($label) . '</th><td class="kalaka-sign">' . esc_html($sign) . '</td><td class="kalaka-amount">'
            . ($strong ? '<strong>' . $text . '</strong>' : $text) . '</td></tr>';
    }

    private static function transferRow(string $label, string $value): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }
}
