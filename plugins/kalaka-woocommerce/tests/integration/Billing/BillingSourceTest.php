<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\WooCommerce\Billing;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\WooCommerce\Card\WooTestCase;
use Kalaka\WooCommerce\BillingField;
use Kalaka\WooCommerce\DeclaredFieldsBilling;
use Kalaka\WooCommerce\OrderMeta;
use Kalaka\WooCommerce\WooProvider;

/**
 * The default billing source, `DeclaredFieldsBilling`: an order carries the registration's values of the five
 * billing fields, the country upper-cased, and missing values left empty (never a refused payment).
 */
final class BillingSourceTest extends WooTestCase
{
    protected function usesHpos(): bool
    {
        return true;
    }

    private function order(RegistrationKey $key): \WC_Order
    {
        $request = new PaymentRequest(
            [new RequestLine($key, 'participant', $this->huf('10000'), 'Teszt tábor: befizetés')],
            Currency::HUF,
            'teszt@example.com'
        );
        (new WooProvider(billing: new DeclaredFieldsBilling()))->payUrl(
            PaymentProviderInput::forStart($request, 'https://example.org/vissza/')
        );

        $orders = wc_get_orders([
            'limit' => -1,
            'status' => 'any',
            'meta_key' => OrderMeta::MARKER,
            'meta_compare' => 'EXISTS',
        ]);
        self::assertIsArray($orders);
        $orders = array_values(array_filter($orders, static fn ($order): bool => $order instanceof \WC_Order));
        self::assertCount(1, $orders);

        return $orders[0];
    }

    /** @param array<string, string> $values field key → stored value */
    private function writeBilling(RegistrationKey $key, array $values): void
    {
        foreach ($values as $field => $value) {
            $this->writeValue($key, $field, $value);
        }
    }

    public function testAFullBillingFillsTheOrderAndUpperCasesTheCountry(): void
    {
        $anna = $this->register('00A0');
        $this->writeBilling($anna, [
            BillingField::NAME => 'Minta Anna',
            BillingField::COUNTRY => 'hu',
            BillingField::POSTCODE => '1234',
            BillingField::CITY => 'Mintaváros',
            BillingField::ADDRESS => 'Minta utca 1.',
        ]);

        $order = $this->order($anna);

        self::assertSame('Minta Anna', $order->get_billing_first_name());
        self::assertSame('HU', $order->get_billing_country());
        self::assertSame('1234', $order->get_billing_postcode());
        self::assertSame('Mintaváros', $order->get_billing_city());
        self::assertSame('Minta utca 1.', $order->get_billing_address_1());
        self::assertSame('teszt@example.com', $order->get_billing_email());
    }

    public function testAPartialBillingFillsOnlyWhatIsSet(): void
    {
        $anna = $this->register('00A0');
        $this->writeBilling($anna, [BillingField::COUNTRY => 'at', BillingField::CITY => 'Bécs']);

        $order = $this->order($anna);

        self::assertSame('', $order->get_billing_first_name());
        self::assertSame('AT', $order->get_billing_country());
        self::assertSame('', $order->get_billing_postcode());
        self::assertSame('Bécs', $order->get_billing_city());
        self::assertSame('', $order->get_billing_address_1());
        self::assertSame('teszt@example.com', $order->get_billing_email());
    }

    public function testNoBillingLeavesTheBillingFieldsEmpty(): void
    {
        $anna = $this->register('00A0');

        $order = $this->order($anna);

        self::assertSame('', $order->get_billing_first_name());
        self::assertSame('', $order->get_billing_country());
        self::assertSame('', $order->get_billing_postcode());
        self::assertSame('', $order->get_billing_city());
        self::assertSame('', $order->get_billing_address_1());
        self::assertSame('teszt@example.com', $order->get_billing_email());
    }
}
