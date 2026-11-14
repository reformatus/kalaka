<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The active registration of the current request, resolved once at `init` (`LinkHandler`): the key the
 * verified cookie names, and whether the previous request was a link that did not match (the one-shot
 * bad-link flag).
 *
 * @internal read through `Api\ActiveRegistration`
 */
final class ActiveRegistrationState
{
    private static ?RegistrationKey $active = null;

    private static bool $badLink = false;

    public static function set(?RegistrationKey $active, bool $badLink): void
    {
        self::$active = $active;
        self::$badLink = $badLink;
    }

    public static function active(): ?RegistrationKey
    {
        return self::$active;
    }

    public static function badLink(): bool
    {
        return self::$badLink;
    }
}
