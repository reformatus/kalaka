<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Forms\Api\DateText;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\ActiveRegistration;
use Kalaka\Foundation\Api\Money\MoneyText;
use Kalaka\Forms\Api\ThemeStyle;
use Kalaka\Portal\Api\Availability;
use Kalaka\Portal\Api\MovementRow;
use Kalaka\Portal\Api\ParticipantMoney;
use Kalaka\Portal\Api\RegistrationStatusView;
use Kalaka\Portal\Api\WithdrawOffer;
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

    /** [UNAPPROVED T765] The button that starts the registrant's own withdrawal. */
    public const WITHDRAW_BUTTON = 'Jelentkezés lemondása';

    /** [UNAPPROVED T766] The confirmation step's question (a heading, N779). */
    public const WITHDRAW_QUESTION = 'Biztosan lemondod a jelentkezésedet?';

    /** [UNAPPROVED T767] The confirming button. */
    public const WITHDRAW_CONFIRM = 'Lemondom a jelentkezést';

    /** [UNAPPROVED T768] Leaves the confirmation step without acting (core's own „Mégsem”, the owner's wording). */
    public const WITHDRAW_CANCEL = 'Mégsem';

    /** The money line's „Befizetve:” (the approved first half of T769; the cases below are [UNAPPROVED T1304 to T1306]). */
    public const WITHDRAW_PAID = 'Befizetve:';

    /** [UNAPPROVED T1303] Case (a): nothing was paid (registered; a waitlisted one shows no line). */
    public const WITHDRAW_NOTHING_PAID = 'Még nem fizettél be semmit, és a lemondás után már nem kell fizetned.';

    /** [UNAPPROVED T1304] Case (b): paid, and all of it is kept; follows „Befizetve: X.”. */
    public const WITHDRAW_KEPT_ALL = 'Ezt az összeget megtartjuk.';

    /** [UNAPPROVED T1305] Case (c): paid, part kept; follows „Befizetve: X.”, then the kept amount and a full stop. */
    public const WITHDRAW_KEPT_PART = 'Ebből ennyit megtartunk:';

    /** [UNAPPROVED T1305] Case (c), second sentence: then the surplus amount and a full stop. */
    public const WITHDRAW_SURPLUS_REST = 'Túlfizetésed lesz:';

    /** [UNAPPROVED T1306] Case (d): paid, nothing kept; follows „Befizetve: X.”. */
    public const WITHDRAW_KEPT_NONE = 'Ebből semmit nem tartunk meg, az egész összeg túlfizetésed lesz.';

    /** [UNAPPROVED T772] Stands in the confirmation of a registered one when the preview gave nothing. */
    public const WITHDRAW_NO_MONEY = 'A befizetett összeget most nem tudjuk megmutatni.';

    /**
     * [UNAPPROVED T770] `kalaka_withdraw_unavailable` (and, T774, `kalaka_status_act_refused`): shown only where
     * it is true, N771; it shows on a page that has just reloaded, so it does not ask for a reload.
     */
    public const WITHDRAW_UNAVAILABLE = 'Ezt a jelentkezést már nem tudod lemondani.';

    /**
     * [UNAPPROVED T771] `kalaka_withdraw_stale`: the page the form came from is out of date. It reads the same as
     * `kalaka_version_conflict` below: the owner made the two one text (2026-11-27), so the registrant sees one
     * sentence whichever of the two the server answers.
     */
    public const WITHDRAW_STALE = 'A jelentkezésed időközben megváltozott. Nézd meg a mostani állapotát.';

    /** `kalaka_version_conflict`: the same text as T771 (merged into it by the owner's ruling, 2026-11-27). */
    public const WITHDRAW_VERSION_CONFLICT = self::WITHDRAW_STALE;

    /**
     * [UNAPPROVED T992] `kalaka_withdraw_closed`: the registration closed between the confirmation page and the press.
     * It stands alone: once the registration has closed the page shows no withdrawal option, no hint (the owner,
     * 2026-11-28).
     */
    public const WITHDRAW_CLOSED = 'A jelentkezés időközben lezárult, ezért a lemondás nem sikerült.';

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

        $html .= '<p>Jelentkezési kód: <span class="kalaka-code has-medium-font-size">' . esc_html($view->key->code)
            . '</span></p>';

        switch ($view->status) {
            case RegistrationStatus::Waitlisted:
                return $html . self::box(
                    '<p><strong>Várólistán vagy.</strong> Amíg nem kerülsz be, nem kell fizetned.</p>',
                    'very-light-gray'
                ) . self::withdraw($withdraw) . '</section>';
            case RegistrationStatus::Withdrawn:
                return $html . '<p><strong>Lemondtad ezt a jelentkezést.</strong></p>' . self::moneySection($view)
                    . self::withdraw($withdraw)
                    . '</section>';
            case RegistrationStatus::Removed:
                return $html . '<p><strong>A szervezők törölték ezt a jelentkezést.</strong></p>'
                    . self::moneySection($view)
                    . self::withdraw($withdraw) . '</section>';
            case RegistrationStatus::Registered:
                break;
        }

        $payment = $view->payment;
        if ($payment === null) {
            return $html . self::withdraw($withdraw) . '</section>';
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

        return $html . $state . self::moneySection($view) . self::payByCard($view) . self::withdraw($withdraw)
            . '</section>';
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

    /**
     * The registrant's own withdrawal [UNAPPROVED N765 to N779]: the button while `can.withdraw`. With script the
     * button opens the confirmation in a WordPress Modal (R370): its texts ride on the button's form as data
     * (`data-kalaka-withdraw`), so the script holds no string, and the page is not re-rendered. Without script
     * the form is a plain GET to the page's own confirm request (`?kalaka_withdraw=confirm`), which shows the
     * same confirmation in place of the button, a plain POST form (the post is the confirmed act). „Mégsem” there
     * is a button of an empty GET form back to the page: a link to an in-page fragment is taken over by Divi's
     * smooth-scroll script. A refused post's text shows by code. After the registration has closed no option
     * shows at all. With nothing to show it renders nothing. The money line comes from the preview's values
     * only, never from the page's own rules (N773).
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
            // A refusal stands alone where no button is left (N1027, N1030).
            return $open . $error . '</div>';
        }
        // A refused post comes back to the error (N779: the fragment moves focus to it, so it is read out).
        $returnUrl = $offer->returnUrl;
        $errorUrl = $returnUrl . '#kalaka-withdraw-error';
        if (!$offer->confirming) {
            $dialog = wp_json_encode([
                'question' => self::WITHDRAW_QUESTION,
                'money' => self::withdrawMoneyLine($offer),
                'confirm' => self::WITHDRAW_CONFIRM,
                'cancel' => self::WITHDRAW_CANCEL,
                'action' => $offer->actionUrl,
                // The hidden fields of the post, by name: the script holds no field name.
                'fields' => [
                    'code' => $offer->code,
                    'return_url' => $errorUrl,
                    Withdrawal::NONCE_FIELD => $offer->nonce,
                ],
            ]);

            return $open . '<form method="get" action="' . esc_url($returnUrl . '#kalaka-withdraw-question') . '"'
                . ' data-kalaka-withdraw="' . esc_attr($dialog === false ? '' : $dialog) . '">'
                . '<button type="submit" name="kalaka_withdraw" value="confirm"' . $class . $describedBy . '>'
                . esc_html(self::WITHDRAW_BUTTON) . '</button></form>' . $error . '</div>';
        }

        return $open . '<form method="post" action="' . esc_url($offer->actionUrl) . '">'
            . '<h3 id="kalaka-withdraw-question" tabindex="-1">' . esc_html(self::WITHDRAW_QUESTION) . '</h3>'
            . self::withdrawMoney($offer)
            . '<input type="hidden" name="code" value="' . esc_attr($offer->code) . '">'
            . '<input type="hidden" name="return_url" value="' . esc_attr($errorUrl) . '">'
            . '<input type="hidden" name="' . esc_attr(Withdrawal::NONCE_FIELD) . '" value="'
            . esc_attr($offer->nonce) . '">'
            . '<div class="kalaka-withdraw-actions" style="display: flex; flex-wrap: wrap; gap: 0.75em">'
            . '<button type="submit"' . $class . $describedBy . '>' . esc_html(self::WITHDRAW_CONFIRM) . '</button>'
            . '<button type="submit" form="kalaka-withdraw-cancel"' . $class . '>' . esc_html(self::WITHDRAW_CANCEL)
            . '</button></div></form>'
            . '<form id="kalaka-withdraw-cancel" method="get" action="' . esc_url($returnUrl . '#kalaka-withdraw')
            . '"></form>'
            . $error . '</div>';
    }

    private static function withdrawMoney(WithdrawOffer $offer): string
    {
        $line = self::withdrawMoneyLine($offer);

        return $line === null ? '' : '<p class="kalaka-withdraw-money">' . esc_html($line) . '</p>';
    }

    /**
     * The confirmation's money line [UNAPPROVED N773, T1303 to T1306]: how much of what was paid is kept, from the
     * preview's values only: `paid`, `retained` (the part of it kept) and `surplus` (the rest). Nobody owes more
     * after withdrawing, so nothing is said about money still to pay.
     *
     *  - (a) nothing paid (a waitlisted registration shows no line);
     *  - (b) paid, no surplus: all kept;
     *  - (c) paid, a surplus and a kept part: part kept, the rest a surplus;
     *  - (d) paid, nothing kept: all of it a surplus.
     *
     * A registered one whose preview gave nothing gets T772; a waitlisted one gets nothing. Null: no line.
     */
    public static function withdrawMoneyLine(WithdrawOffer $offer): ?string
    {
        if ($offer->paid === null || $offer->retained === null || $offer->surplus === null) {
            return $offer->previewFailed ? self::WITHDRAW_NO_MONEY : null;
        }
        if (!$offer->paid->isPositive()) {
            return $offer->waitlisted ? null : self::WITHDRAW_NOTHING_PAID;
        }
        $line = self::WITHDRAW_PAID . ' ' . MoneyText::of($offer->paid) . '.';
        if (!$offer->surplus->isPositive()) {
            return $line . ' ' . self::WITHDRAW_KEPT_ALL;
        }
        if (!$offer->retained->isPositive()) {
            return $line . ' ' . self::WITHDRAW_KEPT_NONE;
        }

        return $line . ' ' . self::WITHDRAW_KEPT_PART . ' ' . MoneyText::of($offer->retained) . '. '
            . self::WITHDRAW_SURPLUS_REST . ' ' . MoneyText::of($offer->surplus) . '.';
    }

    /**
     * [UNAPPROVED N771] What a refused withdrawal shows, by the route's code: new texts for the two withdrawal codes,
     * the registrant's wording for a version conflict, the "no longer possible" text for a refused act, and the
     * generic text of `kalaka_system_failure` for any other code (including `kalaka_site_closed`).
     */
    public static function withdrawRefusalText(string $code): string
    {
        return match ($code) {
            // [UNAPPROVED N1346] An old page's nonce (#253's `kalaka_withdraw_bad_nonce`) reads as T771.
            'kalaka_withdraw_stale', 'kalaka_withdraw_bad_nonce' => self::WITHDRAW_STALE,
            'kalaka_withdraw_unavailable', 'kalaka_status_act_refused' => self::WITHDRAW_UNAVAILABLE,
            'kalaka_version_conflict' => self::WITHDRAW_VERSION_CONFLICT,
            // [UNAPPROVED N1029] The page maps `kalaka_withdraw_closed` itself (extends N771's table).
            'kalaka_withdraw_closed' => self::WITHDRAW_CLOSED,
            default => (new SystemFailure())->publicMessage(),
        };
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
