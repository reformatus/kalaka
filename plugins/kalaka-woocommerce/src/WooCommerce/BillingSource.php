<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Registrations\Api\RegistrationKey;

/**
 * Where an order's billing details come from: the details of a registration's payer, or null when there are none
 * (the order then carries the billing e-mail only). The provider takes one by constructor injection; the default
 * is `NoBilling`.
 */
interface BillingSource
{
    public function of(RegistrationKey $registration): ?BillingDetails;
}
