<?php

declare(strict_types=1);

namespace Kalaka\Pricing\Api;

use Kalaka\Foundation\Api\Money\Money;

/** An amount as plain decimal text without trailing zeros (`15000`, `0.5`), what a money settings file carries. */
final class PlainAmount
{
    public static function of(Money $money): string
    {
        $amount = $money->amount();

        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
