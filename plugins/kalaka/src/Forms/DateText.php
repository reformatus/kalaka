<?php

declare(strict_types=1);

namespace Kalaka\Forms;

/**
 * The text of a calendar day in a document: `<year>. <month name> <day>.` with the Hungarian month in
 * lower case, for example `2026. november 30.` (events-and-forms.md, "Values and conditions"). The one
 * format of every date value.
 */
final class DateText
{
    private const MONTHS = [
        1 => 'január',
        2 => 'február',
        3 => 'március',
        4 => 'április',
        5 => 'május',
        6 => 'június',
        7 => 'július',
        8 => 'augusztus',
        9 => 'szeptember',
        10 => 'október',
        11 => 'november',
        12 => 'december',
    ];

    /**
     * @param string $day a calendar day as `YYYY-MM-DD`
     * @throws \InvalidArgumentException when it is not one
     */
    public static function of(string $day): string
    {
        if (
            preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $day, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new \InvalidArgumentException('A date text is made of a calendar day as YYYY-MM-DD.');
        }

        return sprintf('%d. %s %d.', (int) $parts[1], self::MONTHS[(int) $parts[2]], (int) $parts[3]);
    }
}
