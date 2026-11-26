<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * The limits of a card payment's amount, computed once for the start (`StartCardPayment`) and for the status read
 * alike, so the page and the endpoint can never disagree. The maximum is what is payable now (`full`: no overpaying).
 * The minimum is the site's (the option `kalaka_card_minimum`, below), or the payable unit when the site sets none
 * for the currency, and never above `full`: when `full` is below the minimum, only exactly `full` is accepted.
 *
 * The option is a map of currency code to a decimal text, `{"HUF": "1000"}` by default when it is absent; a currency
 * missing from the map has no minimum; a minimum is rounded up to the payable unit. An option that is not an array
 * (or a JSON text of one), or holds a value that is not a non-negative decimal, is read as the default and reported.
 * [UNAPPROVED N815]
 */
final readonly class CardAmountLimits
{
    public const OPTION = 'kalaka_card_minimum';

    /** @var array<string, string> */
    private const DEFAULT = ['HUF' => '1000'];

    public function __construct(public Money $min, public Money $max)
    {
    }

    /** @param Money $full the registration's payable amount now (positive, in the payable unit) */
    public static function of(Money $full): self
    {
        $currency = $full->currency();
        $unit = self::unit($currency);
        $minimum = self::minimumOf($currency);
        $min = $minimum === null ? $unit : ($minimum->isLessThan($full) ? $minimum : $full);
        if ($min->isLessThan($unit)) {
            $min = $unit;
        }

        return new self($min, $full);
    }

    /** The smallest positive payable amount of the currency. */
    private static function unit(Currency $currency): Money
    {
        $decimals = $currency->payableUnitDecimals();

        return Money::of($decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1', $currency);
    }

    private static function minimumOf(Currency $currency): ?Money
    {
        $map = self::map();
        if (!isset($map[$currency->value])) {
            return null;
        }

        return Money::of($map[$currency->value], $currency)->roundedUpToPayableUnit();
    }

    /** @return array<string, string> the site's map: the stored one, or the default when absent or malformed */
    private static function map(): array
    {
        $stored = get_option(self::OPTION, null);
        if ($stored === null || $stored === false) {
            return self::DEFAULT;
        }
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        $map = [];
        if (is_array($stored)) {
            foreach ($stored as $code => $text) {
                $text = is_int($text) ? (string) $text : $text;
                $valid = is_string($code) && is_string($text)
                    && preg_match('/\A[0-9]{1,18}(\.[0-9]{1,8})?\z/', $text) === 1;
                if (!$valid) {
                    $map = null;
                    break;
                }
                $map[$code] = $text;
            }
        } else {
            $map = null;
        }
        if ($map === null) {
            Boundary::report(
                'card payment minimum',
                new \UnexpectedValueException(self::OPTION . ' is not a map of currency to decimal text.')
            );

            return self::DEFAULT;
        }

        return $map;
    }
}
