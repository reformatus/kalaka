<?php

declare(strict_types=1);

namespace Kalaka\Portal;

/**
 * The refusal code of a withdrawal form post that came back to the page, read once from its flash cookie at
 * `template_redirect` (`WithdrawRefusalCookie`) and held for this request only; the expired cookie makes it
 * one-shot, as the bad link's flag is. Same shape as the card payment's `CardRefusalState`. [UNAPPROVED N660]
 *
 * @internal read through `Api\StatusRead`
 */
final class WithdrawRefusalState
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
