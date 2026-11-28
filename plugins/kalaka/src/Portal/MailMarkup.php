<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Foundation\Api\Money\MoneyText;
use Kalaka\Portal\Api\MovementRow;
use Kalaka\Portal\Api\ParticipantMoney;
use Kalaka\Portal\Api\TransferDetails;

/**
 * The look of the money table and of the transfer block in a mail (mail.md, "Content"). The same read as the page
 * (`ParticipantMoney`, `TransferDetails`) and the same words (`MoneyWords`, `MoneyText`), so the mail and the page
 * agree; a different look, because a mail client runs no popover, no media query and no site stylesheet: inline
 * styles, no info column, no popover, the day inside the method cell, a note in full. A mail is HTML only
 * (mail.md, "Content"), so there is no plain-text twin. Every value is escaped where it is placed.
 */
final class MailMarkup
{
    private const TABLE = 'border-collapse:collapse;width:100%;max-width:600px;font-size:15px;line-height:1.4';

    private const CELL = 'padding:6px 8px;border-top:1px solid #e5e5e5;vertical-align:top';

    private const RULE = 'padding:6px 8px;border-top:1px solid #888;vertical-align:top';

    /** The money table: owed rows, „Fizetendő összesen”, the movements, „Befizetve összesen”, the last row. */
    public static function money(ParticipantMoney $money): string
    {
        $html = '<table cellpadding="0" cellspacing="0" style="' . self::TABLE . '"><tbody>';
        foreach ($money->owed as $row) {
            $html .= self::row(
                $row->label,
                '',
                MoneyWords::sign($row->amount),
                MoneyText::of(MoneyWords::absolute($row->amount)),
            );
        }
        $html .= self::row('Fizetendő összesen', '', '', MoneyText::of($money->owedTotal), italic: true);
        foreach ($money->movements as $index => $movement) {
            $html .= self::movement($movement, $index === 0);
        }
        $html .= self::row(
            'Befizetve összesen',
            '',
            '',
            MoneyText::of($money->paidTotal),
            italic: true,
            rule: $money->movements === [],
        );
        $html .= $money->overpaid !== null
            ? self::row('Túlfizetés', '', '', MoneyText::of($money->overpaid), bold: true, rule: true)
            : self::row('Még fizetendő', '', '', MoneyText::of($money->due ?? $money->balance), bold: true, rule: true);

        return $html . '</tbody></table>';
    }

    /** The transfer details: the sentence of the page block and its four rows. */
    public static function transfer(TransferDetails $transfer): string
    {
        $rows = [
            'Kedvezményezett' => $transfer->beneficiary,
            'Számlaszám' => $transfer->accountNumber,
            'Összeg' => MoneyText::of($transfer->amount),
            'Közlemény' => $transfer->reference,
        ];
        $html = '<p style="margin:0 0 8px">Az átutaláshoz ezeket az adatokat add meg:</p>'
            . '<table cellpadding="0" cellspacing="0" style="' . self::TABLE . '"><tbody>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th scope="row" style="' . self::CELL . ';text-align:left;font-weight:normal">'
                . esc_html($label) . '</th><td style="' . self::CELL . ';text-align:left">' . esc_html($value)
                . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * One movement: its type word, the details (the method, the day, then the whole note in quotes; a correction
     * has no method) in italic, the sign and the amount. The separator is above the first movement. The details'
     * order and form are [UNAPPROVED T7].
     */
    private static function movement(MovementRow $movement, bool $first): string
    {
        $parts = [];
        if ($movement->methodLabel !== null) {
            $parts[] = $movement->methodLabel;
        }
        $parts[] = MoneyWords::day($movement->valueDay);
        if ($movement->note !== null) {
            $parts[] = MoneyWords::quoted($movement->note);
        }

        return self::row(
            MoneyWords::typeOf($movement->type),
            implode(' · ', $parts),
            MoneyWords::sign($movement->amount),
            MoneyText::of(MoneyWords::absolute($movement->amount)),
            rule: $first,
        );
    }

    private static function row(
        string $label,
        string $details,
        string $sign,
        string $amount,
        bool $italic = false,
        bool $bold = false,
        bool $rule = false,
    ): string {
        $cell = ($rule ? self::RULE : self::CELL) . ($italic ? ';font-style:italic' : '')
            . ($bold ? ';font-weight:bold' : '');
        $head = $details === ''
            ? '<th scope="row" colspan="2" style="' . $cell . ';text-align:left;' . ($bold ? '' : 'font-weight:normal')
                . '">'
                . esc_html($label) . '</th>'
            : '<th scope="row" style="' . $cell . ';text-align:left;font-weight:normal">' . esc_html($label) . '</th>'
                . '<td style="' . $cell . ';text-align:left;font-style:italic">' . esc_html($details) . '</td>';

        return '<tr>' . $head . '<td style="' . $cell . ';width:1%;white-space:nowrap;text-align:center">'
            . esc_html($sign) . '</td><td style="' . $cell . ';white-space:nowrap;text-align:right">'
            . esc_html($amount) . '</td></tr>';
    }
}
