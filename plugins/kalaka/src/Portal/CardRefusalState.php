<?php

declare(strict_types=1);

namespace Kalaka\Portal;

/**
 * The refusal code of a card payment form post that came back to the page, read once from its flash cookie at
 * `template_redirect` (`CardRefusalCookie`) and held for this request only. Every status read of the request
 * sees it (the status and transfer blocks alike, in any order); the expired cookie makes it one-shot, as the bad
 * link's flag is.
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

    /** The code of this request, if a refused post came back to it. */
    public static function code(): ?string
    {
        return self::$code;
    }
}
