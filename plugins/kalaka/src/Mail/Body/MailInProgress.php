<?php

declare(strict_types=1);

namespace Kalaka\Mail\Body;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The registration whose mail `MailRenderer` is rendering, innermost last: what the money table and the transfer
 * block of a mail body read (they render for no other registration, so placed on a page or outside a mail they
 * render nothing). Empty outside a mail's render.
 */
final class MailInProgress
{
    /** @var list<RegistrationKey> */
    private static array $registrations = [];

    public static function push(RegistrationKey $registration): void
    {
        self::$registrations[] = $registration;
    }

    public static function pop(): void
    {
        array_pop(self::$registrations);
    }

    public static function registration(): ?RegistrationKey
    {
        return self::$registrations === [] ? null : self::$registrations[array_key_last(self::$registrations)];
    }
}
