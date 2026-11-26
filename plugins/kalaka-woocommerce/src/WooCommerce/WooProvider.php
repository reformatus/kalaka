<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Payments\Api\AbstractPaymentProvider;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RegistrationRef;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * Core's payments port on WooCommerce orders (money.md, "Payments"): a request is one guest order with one item
 * of the hidden product per line, paid on WooCommerce's own order-pay page with whatever gateway the shop
 * has. Only unpaid orders (pending, failed) are ever reused or cancelled; a paid order is never touched.
 */
final class WooProvider extends AbstractPaymentProvider
{
    public const ID = 'woocommerce';

    /** Gateways that take no card: not a way to pay online here. */
    private const OFFLINE = ['bacs', 'cheque', 'cod'];

    public function __construct(
        private readonly OrderFinder $finder = new OrderFinder(),
        private readonly HiddenProduct $product = new HiddenProduct(),
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function accepts(PaymentProviderInput $input): bool
    {
        if (!function_exists('WC') || get_woocommerce_currency() !== $input->currency->value) {
            return false;
        }
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if ($gateway->enabled === 'yes' && !in_array($gateway->id, self::OFFLINE, true)) {
                return true;
            }
        }

        return false;
    }

    public function payUrl(PaymentProviderInput $input): string
    {
        $request = $input->request();
        $returnUrl = $input->returnUrl();
        $wanted = array_map(
            static fn (RequestLine $line): string => KalakaItem::signatureOf(
                $line->registration,
                $line->payer,
                $line->amount
            ),
            $request->lines
        );
        sort($wanted);

        $reusable = null;
        foreach ($this->unpaidOf($request) as $order) {
            $mine = new KalakaOrder($order);
            $have = array_map(static fn (KalakaItem $item): string => $item->signature(), $mine->items());
            sort($have);
            if ($reusable === null && $have === $wanted && $order->get_currency() === $request->currency->value) {
                $reusable = $order;
                continue;
            }
            // Re-read: only an order that is still unpaid is cancelled.
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof \WC_Order && !$fresh->is_paid() && $fresh->has_status(['pending', 'failed'])) {
                $fresh->update_status('cancelled');
            }
        }
        if ($reusable !== null) {
            // The same request: the return address may differ.
            $reusable->update_meta_data(OrderMeta::RETURN_URL, $returnUrl);
            $reusable->save();

            return $reusable->get_checkout_payment_url();
        }

        return $this->create($request, $returnUrl)->get_checkout_payment_url();
    }

    public function latestOf(PaymentProviderInput $input): ?OpenPayment
    {
        $key = $input->registration();
        $orders = $this->finder->of($key, ['any']);
        // An authorised payment that is not paid yet blocks a new one, whichever order is the newest.
        foreach ($orders as $order) {
            if ($order->get_status() === 'on-hold') {
                return $this->open(OpenPaymentState::Processing, $order);
            }
        }
        foreach ($orders as $order) {
            $state = match ($order->get_status()) {
                'pending' => OpenPaymentState::Pending,
                'failed' => OpenPaymentState::Failed,
                // Paid: the request ends once its payment is recorded (the money table shows it from then on).
                'processing', 'completed' => (new KalakaOrder($order))->isRecorded()
                    ? null
                    : OpenPaymentState::Paid,
                default => null,
            };
            if ($state === null) {
                // Cancelled, refunded in full, a draft, paid and recorded: the request is over, and any before it.
                return null;
            }

            return $this->open($state, $order);
        }

        return null;
    }

    private function open(OpenPaymentState $state, \WC_Order $order): OpenPayment
    {
        $since = $order->get_date_paid() ?? $order->get_date_modified() ?? $order->get_date_created();

        return new OpenPayment(
            $state,
            $since === null ? Runner::current()->clock()->now() : new \DateTimeImmutable('@' . $since->getTimestamp()),
            (string) $order->get_id()
        );
    }

    /**
     * The unpaid orders of the registrations of the request, oldest last, each once.
     *
     * @return list<\WC_Order>
     */
    private function unpaidOf(PaymentRequest $request): array
    {
        $orders = [];
        foreach ($request->lines as $line) {
            foreach ($this->finder->of($line->registration, ['pending', 'failed']) as $order) {
                $orders[$order->get_id()] = $order;
            }
        }
        krsort($orders);

        return array_values($orders);
    }

    private function create(PaymentRequest $request, string $returnUrl): \WC_Order
    {
        $order = wc_create_order(['status' => 'pending', 'customer_id' => 0]);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('WooCommerce did not create the order.');
        }
        // `wc_create_order()` takes the shop's currency whatever is passed, and stores the requesting browser's
        // address and user agent: the order holds the request's currency and none of the registrant's browser.
        $order->set_currency($request->currency->value);
        $order->set_customer_ip_address('');
        $order->set_customer_user_agent('');
        $order->set_prices_include_tax(false);
        if ($request->email !== null && $request->email !== '') {
            $order->set_billing_email($request->email);
        }
        $productId = $this->product->id();
        $product = wc_get_product($productId);
        foreach ($request->lines as $line) {
            $amount = wc_format_decimal($line->amount->amount());
            $item = new \WC_Order_Item_Product();
            if ($product instanceof \WC_Product) {
                $item->set_product($product);
            }
            $item->set_name($line->label);
            $item->set_quantity(1);
            $item->set_subtotal($amount);
            $item->set_total($amount);
            $item->set_taxes([]);
            $item->add_meta_data(OrderMeta::ITEM_REGISTRATION, RegistrationRef::compose($line->registration), true);
            $item->add_meta_data(OrderMeta::ITEM_PAYER, $line->payer, true);
            $order->add_item($item);
            // One row per line, so the order is found by its registration without reading its items.
            $order->add_meta_data(OrderMeta::REGISTRATION, RegistrationRef::compose($line->registration), false);
        }
        $order->update_meta_data(OrderMeta::MARKER, '1');
        $order->update_meta_data(OrderMeta::RETURN_URL, $returnUrl);
        $order->calculate_totals(false);
        $order->save();

        return $order;
    }
}
