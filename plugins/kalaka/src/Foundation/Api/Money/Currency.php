<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Api\Money;

enum Currency: string
{
    case HUF = 'HUF';

    /**
     * Decimals of the smallest unit of account (HUF: one fillér is 0.01).
     *
     * @return int<0, max>
     */
    public function minorUnitDecimals(): int
    {
        return match ($this) {
            self::HUF => 2,
        };
    }

    /**
     * Decimals of the unit a payment request is rounded up to (HUF: whole forints).
     *
     * @return int<0, max>
     */
    public function payableUnitDecimals(): int
    {
        return match ($this) {
            self::HUF => 0,
        };
    }
}
