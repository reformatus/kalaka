<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce;

/** A core `Access` stand-in whose read throws: the gate must keep WooCommerce's own answer. */
final class ThrowingAccess
{
    public static function mayReachAdmin(int $userId): bool
    {
        throw new \RuntimeException('Teszt hiba a hozzáférés ellenőrzése közben.');
    }
}
