<?php

declare(strict_types=1);

namespace Kalaka\Payments\Api;

use Kalaka\Foundation\Api\Money\Currency;

/**
 * What a payment provider is asked to collect: N lines in the one currency and the address a receipt may go to. The
 * input of `PaymentProvider::payUrl()`.
 */
final readonly class PaymentRequest
{
    /**
     * @param list<RequestLine> $lines at least one
     * @param ?string $email the registration's contact address, or null when it has none
     * @throws \InvalidArgumentException
     */
    public function __construct(public array $lines, public Currency $currency, public ?string $email = null)
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('A payment request has at least one line.');
        }
    }
}
