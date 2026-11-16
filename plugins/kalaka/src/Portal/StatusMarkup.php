<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\DateText;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\Availability;
use Kalaka\Portal\Api\PaymentView;
use Kalaka\Portal\Api\RegistrationStatusView;
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

    private const MINUS = "\u{2212}";

    /** The status block "Jelentkezésem állapota". */
    public static function status(?RegistrationStatusView $view, bool $badLink): string
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
                ) . '</section>';
            case RegistrationStatus::Withdrawn:
                return $html . '<p><strong>Lemondtad ezt a jelentkezést.</strong></p></section>';
            case RegistrationStatus::Removed:
                return $html . '<p><strong>A szervezők törölték ezt a jelentkezést.</strong></p></section>';
            case RegistrationStatus::Registered:
                break;
        }

        $payment = $view->payment;
        if ($payment === null) {
            return $html . '</section>';
        }
        $confirmed = $view->confirmation !== null && $view->confirmation->confirmed;
        if ($payment->held) {
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

        return $html . $state . '<h3>Pénzügyek</h3>' . self::payment($payment) . '</section>';
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
        $payableNow = $due->isPositive() && !$payment->held;
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
