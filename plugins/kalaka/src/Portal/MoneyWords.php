<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Portal\Api\MovementRow;
use Kalaka\Portal\Api\MovementType;

/**
 * The words of the money table that the page and the mail both use (owner topics 10 and 11, rounds 2 and 3): the
 * type word of a movement, its quoted note, the note shortened for the page, the day, and a sign. One place, so
 * the mail and the page agree. Pure text: nothing here reads data or escapes.
 */
final class MoneyWords
{
    /** A note is shown up to this many characters on the page, then „…” (P12, owner-approved). */
    public const NOTE_LENGTH = 36;

    public const MINUS = "\u{2212}";

    /** The type word of a movement: „Befizetés”, „Visszafizetés” or „Korrekció” (owner words). */
    public static function typeOf(MovementType $type): string
    {
        return match ($type) {
            MovementType::Payment => 'Befizetés',
            MovementType::Refund => 'Visszafizetés',
            MovementType::Correction => 'Korrekció',
        };
    }

    /**
     * The note in Hungarian quotes, exactly as typed: quotes of its own are not converted (the page, the popover
     * and the mail read the same function). [UNAPPROVED N1261]
     */
    public static function quoted(string $text): string
    {
        return "\u{201E}" . $text . "\u{201D}";
    }

    /** The note cut at `NOTE_LENGTH` characters with „…” inside the quotes; the note itself when it is short. */
    public static function shortened(string $note): string
    {
        if (mb_strlen($note) <= self::NOTE_LENGTH) {
            return $note;
        }

        return rtrim(mb_substr($note, 0, self::NOTE_LENGTH)) . "\u{2026}";
    }

    /** A `Y-m-d` day as the table's column shows it: `2026. 11. 05.` */
    public static function day(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date === false ? $day : $date->format('Y. m. d.');
    }

    /** The sign of a signed amount: „+” or „−”. */
    public static function sign(Money $amount): string
    {
        return $amount->isNegative() ? self::MINUS : '+';
    }

    /** The amount without its sign. */
    public static function absolute(Money $amount): Money
    {
        return $amount->isNegative() ? $amount->negated() : $amount;
    }

    /** The line of a movement that the popover reads: `Type · Method · day`, a correction's without method. */
    public static function headline(MovementRow $movement): string
    {
        return implode(' · ', array_filter([
            self::typeOf($movement->type),
            $movement->methodLabel,
            self::day($movement->valueDay),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
