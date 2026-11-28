<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\DateText;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Foundation\Api\Money\MoneyText;
use Kalaka\Forms\Api\ThemeStyle;
use Kalaka\Portal\Api\Availability;
use Kalaka\Portal\Api\MovementRow;
use Kalaka\Portal\Api\ParticipantMoney;
use Kalaka\Portal\Api\RegistrationStatusView;
use Kalaka\Portal\Rest\CardPaymentRoute;
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

    public const BAD_LINK = ActiveRegistration::BAD_LINK_TEXT;


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

        $html .= '<p>Jelentkezési kód: <span class="kalaka-code has-medium-font-size">' . esc_html($view->key->code)
            . '</span></p>';

        switch ($view->status) {
            case RegistrationStatus::Waitlisted:
                return $html . self::box(
                    '<p><strong>Várólistán vagy.</strong> Amíg nem kerülsz be, nem kell fizetned.</p>',
                    'very-light-gray'
                ) . '</section>';
            case RegistrationStatus::Withdrawn:
                return $html . '<p><strong>Lemondtad ezt a jelentkezést.</strong></p>' . self::moneySection($view)
                    . '</section>';
            case RegistrationStatus::Removed:
                return $html . '<p><strong>A szervezők törölték ezt a jelentkezést.</strong></p>'
                    . self::moneySection($view) . '</section>';
            case RegistrationStatus::Registered:
                break;
        }

        $payment = $view->payment;
        if ($payment === null) {
            return $html . '</section>';
        }
        $confirmed = $view->confirmation !== null && $view->confirmation->confirmed;
        if ($payment->overdue) {
            $state = self::box('<p><strong>Lejárt a fizetési határidő.</strong></p>', 'pale-pink');
        } elseif ($confirmed) {
            // SQ-8: the text on handling an overpayment is the stakeholders'; until then the table's
            // "Túlfizetés" row carries the amount and the box says nothing more.
            $state = self::box('<p><strong>Visszaigazolva.</strong></p>', 'light-green-cyan');
        } else {
            $state = '<p><strong>Fizetésre vár.</strong></p>';
        }

        return $html . $state . self::moneySection($view) . self::payByCard($view) . '</section>';
    }

    /**
     * A bare link to pay by card, only while `can.payCard`: a form that posts to the card payment route, with
     * no look of its own. [UNAPPROVED T81] It exists so the flow can be tried; the button is the UX prototype's.
     */
    private static function payByCard(RegistrationStatusView $view): string
    {
        if (!$view->can->payCard) {
            return '';
        }
        $back = is_singular() ? get_permalink() : false;

        return '<form class="kalaka-pay-card" method="post" action="' . esc_url(CardPaymentRoute::url()) . '">'
            . '<input type="hidden" name="_wpnonce" value="'
            . esc_attr(wp_create_nonce(CardPaymentRoute::NONCE_ACTION)) . '">'
            . ($back === false ? '' : '<input type="hidden" name="return_url" value="' . esc_attr($back) . '">')
            . '<button type="submit" class="' . esc_attr(ThemeStyle::buttonClass()) . '">'
            . 'Fizetés bankkártyával</button></form>';
    }

    /**
     * "Pénzügyek" with the money table and, while payment is open, the deadline, and the note under the table.
     * A withdrawn or removed registration always shows the table (owner topic 11) and has no deadline line.
     */
    private static function moneySection(RegistrationStatusView $view): string
    {
        if (!$view->showsMoney()) {
            return '';
        }
        $money = $view->money;
        $html = '<h3>Pénzügyek</h3>' . self::table($money);
        $payment = $view->payment;
        if (
            $payment !== null && $money->due !== null && $money->due->isPositive() && !$payment->awaitsAnswer
            && $payment->paymentDue !== null
        ) {
            $day = $payment->paymentDue->setTimezone(wp_timezone())->modify('-1 day')->format('Y-m-d');
            $html .= '<p class="kalaka-deadline">Fizetési határidő: ' . esc_html(DateText::of($day)) . '</p>';
        }

        return $html . '<p>Az átutalásokat kézzel rögzítjük, ezért néhány napba is telhet, mire itt megjelennek.</p>';
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
     * The leave block: one bare form with one button, no look of its own beyond core's button class. The
     * label is a stand-in.
     */
    public static function leave(string $action, string $returnUrl): string
    {
        return '<form class="kalaka-leave" method="post" action="' . esc_url($action) . '">'
            . '<input type="hidden" name="return_url" value="' . esc_attr($returnUrl) . '">'
            . '<button type="submit" class="wp-element-button">' . esc_html(ParticipantBlocks::LEAVE_LABEL)
            . '</button></form>';
    }

    /**
     * The money table of the owner's design (topics 10 and 11, rounds 2 and 3): the owed lines as a signed running
     * sum with „Fizetendő összesen”, one row per money movement, „Befizetve összesen”, and the last row, always
     * bold: „Még fizetendő”, or „Túlfizetés” when overpaid. A separator above the first movement (above
     * „Befizetve összesen” when nothing was paid) and above the last row.
     */
    private static function table(ParticipantMoney $money): string
    {
        $html = '<table class="kalaka-money"><tbody>';
        foreach ($money->owed as $row) {
            $html .= self::moneyRow(
                $row->label,
                MoneyWords::sign($row->amount),
                MoneyWords::absolute($row->amount),
                '',
                'kalaka-owed'
            );
        }
        $html .= self::moneyRow('Fizetendő összesen', '', $money->owedTotal, 'em', 'kalaka-total');
        foreach ($money->movements as $movement) {
            $html .= self::movementRow($movement, $movement === $money->movements[0]);
        }
        $html .= self::moneyRow(
            'Befizetve összesen',
            '',
            $money->paidTotal,
            'em',
            $money->movements === [] ? 'kalaka-total kalaka-rule' : 'kalaka-total'
        );
        $html .= $money->overpaid !== null
            ? self::moneyRow('Túlfizetés', '', $money->overpaid, 'strong', 'kalaka-rule kalaka-due')
            : self::moneyRow('Még fizetendő', '', $money->due ?? $money->balance, 'strong', 'kalaka-rule kalaka-due');

        return $html . '</tbody></table>';
    }

    /** A state inside a core group with a default-palette background; the text stays black on it. */
    private static function box(string $inner, string $slug): string
    {
        return '<div class="wp-block-group has-background has-' . $slug . '-background-color has-text-color'
            . ' has-black-color">' . $inner . '</div>';
    }

    /**
     * An owed or total row: the label spans the type and method columns; no day, no info button. `$emphasis` is
     * the element that carries the row's emphasis in the markup, so it reads without any CSS: `em` for the totals
     * (owner topic 10), `strong` for the last row (always bold), `''` for an owed line.
     */
    private static function moneyRow(
        string $label,
        string $sign,
        Money $amount,
        string $emphasis = '',
        string $class = '',
    ): string {
        return '<tr' . ($class === '' ? '' : ' class="' . esc_attr($class) . '"') . '><th scope="row" colspan="2">'
            . self::emphasised($emphasis, esc_html($label)) . '</th><td class="kalaka-sign">' . esc_html($sign)
            . '</td><td class="kalaka-amount">' . self::emphasised($emphasis, esc_html(MoneyText::of($amount)))
            . '</td><td class="kalaka-date"></td><td class="kalaka-info"></td></tr>';
    }

    /** Escaped HTML inside the emphasis element (`em` or `strong`), or as it is for no emphasis or no content. */
    private static function emphasised(string $element, string $html): string
    {
        return $element === '' || $html === '' ? $html : '<' . $element . '>' . $html . '</' . $element . '>';
    }

    /**
     * One money movement: the type word (row header), the method cell (italic; the method, then its note after
     * ` · `; a correction's text alone), the sign, the amount, the day (always italic) and the info mark. The
     * details (`Type · Method · day` and the whole note) are the browser's own tooltip, the `title` of the info mark
     * and of a shortened note, and the info mark's accessible name. The info mark shows on every row up to 1160 px,
     * and above that only on a row with a shortened note (`kalaka-more`). [UNAPPROVED N1262]
     */
    private static function movementRow(MovementRow $movement, bool $first): string
    {
        $note = $movement->note;
        $short = $note === null ? null : MoneyWords::shortened($note);
        $cut = $note !== null && $short !== $note;
        $details = MoneyWords::headline($movement) . ($note === null ? '' : "\n" . MoneyWords::quoted($note));
        $noteCell = $short === null ? '' : ($cut
            ? '<span class="kalaka-note" title="' . esc_attr($details) . '">'
                . esc_html(MoneyWords::quoted($short)) . '</span>'
            : esc_html(MoneyWords::quoted($short)));
        $method = $movement->methodLabel;
        $cell = $method === null
            ? $noteCell
            : esc_html($method) . ($noteCell === '' ? '' : ' · ' . $noteCell);
        $day = MoneyWords::day($movement->valueDay);
        $classes = 'kalaka-move' . ($first ? ' kalaka-rule' : '') . ($cut ? ' kalaka-more' : '');

        return '<tr class="' . $classes . '"><th scope="row">' . esc_html(MoneyWords::typeOf($movement->type))
            . '</th><td class="kalaka-method">' . self::emphasised('em', $cell) . '</td><td class="kalaka-sign">'
            . esc_html(MoneyWords::sign($movement->amount)) . '</td><td class="kalaka-amount">'
            . esc_html(MoneyText::of(MoneyWords::absolute($movement->amount))) . '</td><td class="kalaka-date">'
            . self::emphasised('em', esc_html($day)) . '</td><td class="kalaka-info">'
            . '<span class="kalaka-info-button" role="img" title="' . esc_attr($details) . '" aria-label="'
            . esc_attr($details) . '">ⓘ</span></td></tr>';
    }

    private static function transferRow(string $label, string $value): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }
}
