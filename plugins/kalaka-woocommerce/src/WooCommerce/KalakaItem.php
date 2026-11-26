<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Payments\Api\RegistrationRef;
use Kalaka\Registrations\Api\RegistrationKey;

/** One request line as an order item holds it: the item's ID, its registration and payer, and its amounts. */
final readonly class KalakaItem
{
    /**
     * @param Money $requested what the line asked for (the item's subtotal, before any coupon)
     * @param Money $charged what the item actually charged (its total, after a coupon)
     */
    public function __construct(
        public int $id,
        public RegistrationKey $registration,
        public string $payer,
        public Money $requested,
        public Money $charged,
    ) {
    }

    /** The line as a comparable text: registration, payer and requested amount. */
    public function signature(): string
    {
        return self::signatureOf($this->registration, $this->payer, $this->requested);
    }

    /** The one composition of a line's signature, for an order's item and for a request line alike. */
    public static function signatureOf(RegistrationKey $registration, string $payer, Money $amount): string
    {
        return RegistrationRef::compose($registration) . '|' . $payer . '|' . $amount->amount();
    }
}
