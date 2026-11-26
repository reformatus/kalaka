<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Registrations\Api\RegistrationKey;

/** The default billing source: none, so an order carries the billing e-mail only. [UNAPPROVED N818] */
final class NoBilling implements BillingSource
{
    public function of(RegistrationKey $registration): ?BillingDetails
    {
        return null;
    }
}
