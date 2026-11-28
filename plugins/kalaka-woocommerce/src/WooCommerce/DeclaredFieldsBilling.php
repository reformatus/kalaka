<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Registrations\Api\Registrations;

/**
 * The real billing source: the registration's values of the five billing fields the module declares, read
 * through core's `Registrations::values()`. The country is the stored lower-case ISO 3166-1 alpha-2 code,
 * returned upper-cased. [UNAPPROVED N818]
 */
final class DeclaredFieldsBilling implements BillingSource
{
    public function __construct(
        private readonly Registrations $registrations = new Registrations(),
    ) {
    }

    public function of(RegistrationKey $registration): BillingDetails
    {
        $values = $this->registrations->values($registration);

        // [UNAPPROVED N822] A missing billing value is left empty, never a refused payment.
        return new BillingDetails(
            self::text($values, BillingField::NAME),
            self::country($values),
            self::text($values, BillingField::POSTCODE),
            self::text($values, BillingField::CITY),
            self::text($values, BillingField::ADDRESS),
        );
    }

    /** @param array<string, string> $values */
    private static function text(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $values */
    private static function country(array $values): ?string
    {
        $value = $values[BillingField::COUNTRY] ?? null;
        if ($value === null || preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }
}
