<?php

declare(strict_types=1);

namespace Kalaka\Portal;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * The text of an amount in the participant blocks: `48 000 Ft`, with a no-break space between the groups
 * of thousands and before the currency, fillérs after a comma only when there are any (`1 234,50 Ft`),
 * and U+2212 before a negative amount.
 */
final class MoneyText
{
    private const NBSP = "\u{00A0}";

    public static function of(Money $money): string
    {
        $currency = $money->currency();
        [$whole, $fraction] = array_pad(explode('.', $money->amount(), 2), 2, '');
        $negative = str_starts_with($whole, '-');
        $whole = ltrim($whole, '-');
        $fraction = substr(str_pad($fraction, $currency->minorUnitDecimals(), '0'), 0, $currency->minorUnitDecimals());
        $grouped = (string) preg_replace('/\B(?=(?:[0-9]{3})+\z)/', self::NBSP, $whole);
        $text = $grouped . (trim($fraction, '0') === '' ? '' : ',' . $fraction);
        $isZero = trim($whole, '0') === '' && trim($fraction, '0') === '';

        return ($negative && !$isZero ? "\u{2212}" : '') . $text . self::NBSP . self::symbol($currency);
    }

    private static function symbol(Currency $currency): string
    {
        return match ($currency) {
            Currency::HUF => 'Ft',
        };
    }
}
