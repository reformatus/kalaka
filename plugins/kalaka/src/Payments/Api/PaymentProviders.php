<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Module\Contributions;

/**
 * The payment provider the site uses: the first one contributed. A second one is reported to the error log once
 * per request and never used (a site warning for it waits for an approved text).
 */
final class PaymentProviders
{
    private static bool $reported = false;

    public function active(): ?PaymentProvider
    {
        $providers = Contributions::current()->paymentProviders();
        if (count($providers) > 1 && !self::$reported) {
            self::$reported = true;
            Boundary::report(
                'Payment providers',
                new \LogicException(
                    'More than one payment provider is contributed; ' . $providers[0]->id() . ' is used.'
                )
            );
        }

        return $providers[0] ?? null;
    }
}
