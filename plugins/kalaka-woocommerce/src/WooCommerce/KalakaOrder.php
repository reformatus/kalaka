<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Payments\Api\RegistrationRef;

/** A WooCommerce order as this plugin reads it: whether it is one of ours, and its request lines. */
final class KalakaOrder
{
    public function __construct(public readonly \WC_Order $order)
    {
    }

    public static function of(int $orderId): ?self
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return null;
        }
        $mine = new self($order);

        return $mine->isKalaka() ? $mine : null;
    }

    public function isKalaka(): bool
    {
        return $this->order->get_meta(OrderMeta::MARKER, true) !== '';
    }

    public function isRecorded(): bool
    {
        return $this->order->get_meta(OrderMeta::RECORDED, true) !== '';
    }

    public function currency(): ?Currency
    {
        return Currency::tryFrom($this->order->get_currency());
    }

    /**
     * The order's request lines, in item order. An item that does not name a registration of ours is not one.
     *
     * @return list<KalakaItem>
     */
    public function items(): array
    {
        $currency = $this->currency();
        if ($currency === null) {
            return [];
        }
        $items = [];
        foreach ($this->order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $key = RegistrationRef::parse((string) $item->get_meta(OrderMeta::ITEM_REGISTRATION, true));
            if ($key === null) {
                continue;
            }
            $items[] = new KalakaItem(
                $item->get_id(),
                $key,
                (string) $item->get_meta(OrderMeta::ITEM_PAYER, true),
                Money::of(wc_format_decimal((string) $item->get_subtotal()), $currency),
                Money::of(wc_format_decimal((string) $item->get_total()), $currency),
            );
        }

        return $items;
    }
}
