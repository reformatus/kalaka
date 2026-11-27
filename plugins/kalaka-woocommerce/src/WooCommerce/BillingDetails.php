<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

/**
 * What an order's billing fields may carry besides the e-mail: the payer's name, country (ISO 3166-1 alpha-2),
 * postcode, city and address, each optional. An empty text is none.
 */
final readonly class BillingDetails
{
    /** @throws \InvalidArgumentException when a country is given and is not two letters */
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public ?string $address = null,
    ) {
        if ($country !== null && $country !== '' && preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            throw new \InvalidArgumentException('A billing country is an ISO 3166-1 alpha-2 code.');
        }
    }
}
