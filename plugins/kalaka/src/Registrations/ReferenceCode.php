<?php

declare(strict_types=1);

namespace Kalaka\Registrations;

/**
 * The reference-code rule: registrations-and-identity.md, "Reference codes". Two digits, a letter of
 * the list, then one to thirteen digits; the first four characters carry the check.
 */
final class ReferenceCode
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 16;

    /** The letters in the order of their index, 0 to 13. */
    public const LETTERS = 'AKBEGJTZFLRSXI';

    /** @var ?list<string> */
    private static ?array $short = null;

    public static function isValid(string $code): bool
    {
        if (preg_match('/^[0-9]{2}[' . self::LETTERS . '][0-9]{1,13}$/D', $code) !== 1) {
            return false;
        }

        $letter = (int) strpos(self::LETTERS, $code[2]);

        return (9 * (int) $code[0] + 5 * (int) $code[1] + 9 * $letter + 9 * (int) $code[3]) % 14 < 5;
    }

    /**
     * Every valid code of four characters, in code order.
     *
     * @return list<string>
     */
    public static function fourCharacterCodes(): array
    {
        if (self::$short === null) {
            $codes = [];
            for ($leading = 0; $leading < 100; $leading++) {
                foreach (str_split(self::LETTERS) as $letter) {
                    for ($last = 0; $last < 10; $last++) {
                        $code = sprintf('%02d%s%d', $leading, $letter, $last);
                        if (self::isValid($code)) {
                            $codes[] = $code;
                        }
                    }
                }
            }
            self::$short = $codes;
        }

        return self::$short;
    }
}
