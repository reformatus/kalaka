<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Payments\Api\OpenPayment;
use Kalaka\Payments\Api\OpenPaymentState;
use Kalaka\Payments\Api\AbstractPaymentProvider;
use Kalaka\Payments\Api\PaymentProviderInput;
use Kalaka\Payments\Api\PaymentRequest;
use Kalaka\Payments\Api\RegistrationRef;
use Kalaka\Payments\Api\RequestLine;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * Core's payments port on WooCommerce orders (money.md, "Payments"): a request is one order (a guest order, or the
 * owner account's when it is the one paying; see `OrderOwner`) with one item of the hidden product per line, paid
 * on WooCommerce's own order-pay page with whatever gateway the shop has. [UNAPPROVED N325] A new request makes one
 * new order, then cancels the registration's never-paid orders (pending, failed); a paid order is never touched,
 * and an order in any other open state refuses the start (see `latestOf()`).
 */
final class WooProvider extends AbstractPaymentProvider
{
    public const ID = 'woocommerce';

    /** Gateways that take no card: not a way to pay online here. */
    private const OFFLINE = ['bacs', 'cheque', 'cod'];

    public function __construct(
        private readonly OrderFinder $finder = new OrderFinder(),
        private readonly HiddenProduct $product = new HiddenProduct(),
        private readonly CardSurcharge $surcharge = new CardSurcharge(),
        private readonly OrderOwner $owner = new OrderOwner(),
        private readonly BillingSource $billing = new NoBilling(),
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
        // A new start never reuses an order: one new order is made first, then every unpaid order of these
        // registrations (the new one excluded) is cancelled, so at most one unpaid order exists. Core makes this
        // call under the registration's lock, and only when `latestOf()` found nothing open at the gateway
        // (anything else refuses the start first). Making the new order before cancelling the old ones means a
        // failed create leaves the registrant's earlier unpaid order open.
        $order = $this->create($request, $input->returnUrl());
        foreach ($this->unpaidOf($request) as $unpaid) {
            if ($unpaid->get_id() === $order->get_id()) {
                continue;
            }
            // Re-read: only an order that is still unpaid is cancelled.
            $fresh = wc_get_order($unpaid->get_id());
            if ($fresh instanceof \WC_Order && !$fresh->is_paid() && $fresh->has_status(['pending', 'failed'])) {
                $fresh->update_status('cancelled');
            }
        }

        return $order->get_checkout_payment_url();
    }

    /**
     * The latest payment request, and whether anything is still open: an order that is neither closed nor an
     * unpaid one (pending, failed) is money that may be on its way, so it is answered before the newest order is
     * looked at, whichever order it is. `Processing` is the answer for every such order Kaláka does not handle
     * itself (on hold, and any status another plugin or WooCommerce added): core reads it as "an earlier card
     * payment is still open" and refuses the start before anything is cancelled or created.
     */
    public function latestOf(PaymentProviderInput $input): ?OpenPayment
    {
        $orders = $this->finder->of($input->registration(), $this->everyStatus());
        foreach ($orders as $order) {
            $open = $this->blocking($order);
            if ($open !== null) {
                return $this->open($open, $order);
            }
        }
        $newest = $orders[0] ?? null;
        if ($newest === null) {
            return null;
        }
        $state = match ($newest->get_status()) {
            'pending' => OpenPaymentState::Pending,
            'failed' => OpenPaymentState::Failed,
            // Cancelled, refunded in full, or nothing: no open request.
            default => null,
        };

        return $state === null ? null : $this->open($state, $newest);
    }

    /**
     * Whether the order is open in a way that blocks a new start. Fail closed: the statuses that do not block are
     * listed, so a status nobody foresaw blocks.
     */
    private function blocking(\WC_Order $order): ?OpenPaymentState
    {
        return match ($order->get_status()) {
            // Never paid: a new start cancels it. Closed: cancelled, refunded (and trashed, which is not read).
            'pending', 'failed', 'cancelled', 'refunded', 'trash' => null,
            // Paid: it ends once its payment is recorded (the money table shows it from then on).
            'processing', 'completed' => (new KalakaOrder($order))->isRecorded() ? null : OpenPaymentState::Paid,
            // On hold, a draft, or any status Kaláka does not handle.
            default => OpenPaymentState::Processing,
        };
    }

    /** @return list<string> every status an order can have (but trash), with the draft status of the blocks */
    private function everyStatus(): array
    {
        return [...array_keys(wc_get_order_statuses()), 'wc-checkout-draft'];
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

    /** The request's card surcharge: the fee of each event's lines, added up; null when there is none. */
    private function feeOf(PaymentRequest $request): ?Money
    {
        $perEvent = [];
        foreach ($request->lines as $line) {
            $event = $line->registration->eventId;
            $perEvent[$event] = isset($perEvent[$event]) ? $perEvent[$event]->plus($line->amount) : $line->amount;
        }
        $total = null;
        foreach ($perEvent as $event => $amount) {
            $fee = $this->surcharge->feeFor($event, $amount);
            if ($fee !== null) {
                $total = $total === null ? $fee : $total->plus($fee);
            }
        }

        return $total;
    }

    /**
     * The billing details of the request's first registration, when the source has any: the whole
     * name in the first-name field (the last name stays empty; WooCommerce needs neither for an order made here),
     * and only the non-empty values.
     */
    private function setBilling(\WC_Order $order, PaymentRequest $request): void
    {
        $details = $this->billing->of($request->lines[0]->registration);
        if ($details === null) {
            return;
        }
        if ($details->name !== null && $details->name !== '') {
            $order->set_billing_first_name($details->name);
        }
        if ($details->country !== null && $details->country !== '') {
            $order->set_billing_country(strtoupper($details->country));
        }
        if ($details->postcode !== null && $details->postcode !== '') {
            $order->set_billing_postcode($details->postcode);
        }
        if ($details->city !== null && $details->city !== '') {
            $order->set_billing_city($details->city);
        }
        if ($details->address !== null && $details->address !== '') {
            $order->set_billing_address_1($details->address);
        }
    }

    private function create(PaymentRequest $request, string $returnUrl): \WC_Order
    {
        // [UNAPPROVED N327] The owner account's order when it is the one paying, else a guest order.
        $order = wc_create_order(['status' => 'pending', 'customer_id' => $this->owner->customerOf($request)]);
        if (!$order instanceof \WC_Order) {
            throw new \RuntimeException('WooCommerce did not create the order.');
        }
        // The order is saved by `wc_create_order()` already, before it carries the marker. If filling it in fails, the
        // half-made order is deleted, so no unmarked pending order is left behind.
        try {
            $this->fill($order, $request, $returnUrl);
        } catch (\Throwable $e) {
            $order->delete(true);

            throw $e;
        }

        return $order;
    }

    private function fill(\WC_Order $order, PaymentRequest $request, string $returnUrl): void
    {
        // `wc_create_order()` takes the shop's currency whatever is passed, and stores the requesting browser's
        // address and user agent: the order holds the request's currency and none of the registrant's browser.
        $order->set_currency($request->currency->value);
        $order->set_customer_ip_address('');
        $order->set_customer_user_agent('');
        $order->set_prices_include_tax(false);
        if ($request->email !== null && $request->email !== '') {
            $order->set_billing_email($request->email);
        }
        $this->setBilling($order, $request);
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
        $fee = $this->feeOf($request);
        if ($fee !== null) {
            // The card surcharge: one fee line, no tax, never ledger money (payments record only the items above).
            $line = new \WC_Order_Item_Fee();
            $line->set_name('Kártyás fizetés díja');
            $line->set_tax_status('none');
            $line->set_tax_class('');
            $line->set_total(wc_format_decimal($fee->amount()));
            $line->set_taxes([]);
            $order->add_item($line);
        }
        $order->update_meta_data(OrderMeta::MARKER, '1');
        $order->update_meta_data(OrderMeta::RETURN_URL, $returnUrl);
        $order->calculate_totals(false);
        $order->save();
    }
}
