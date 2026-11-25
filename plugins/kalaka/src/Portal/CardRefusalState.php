<?php

declare(strict_types=1);

namespace Kalaka\Portal;

/**
 * The refusal code of a card payment form post that came back to the page, read once from its flash cookie at
 * `template_redirect` (`CardRefusalCookie`): held for the request, and taken by the active registration's status
 * read, which shows it once.
 *
 * @internal read through `Api\StatusRead`
 */
final class CardRefusalState
{
    private static ?string $code = null;

    public static function set(?string $code): void
    {
        self::$code = $code;
    }

    /** The code, once: a second read finds none. */
    public static function take(): ?string
    {
        $code = self::$code;
        self::$code = null;

        return $code;
    }
}
