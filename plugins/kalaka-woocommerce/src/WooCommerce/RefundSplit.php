<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Money\Money;

/**
 * Which order items a refund gave back, and how much of each. A refund that names its items gives them as
 * they are. One that names none (an amount only) goes to the one item of a one-item order, and on an order of
 * several is split in item order, each item up to what it has left (its charge less what the refunds before
 * it gave back).
 */
final class RefundSplit
{
    /** @var array<int, Money> by item ID */
    public array $amounts = [];

    /** Whether the amount was split over the items by this plugin. */
    public bool $allocated = false;

    public function of(KalakaOrder $mine, \WC_Order_Refund $refund): self
    {
        $currency = $mine->currency();
        $items = $mine->items();
        if ($currency === null || $items === []) {
            return $this;
        }
        $left = [];
        foreach ($items as $item) {
            $left[$item->id] = $item->charged;
        }

        // The order's refunds up to this one, oldest first, so each is split against what the earlier left.
        $refunds = $mine->order->get_refunds();
        usort(
            $refunds,
            static fn (\WC_Order_Refund $a, \WC_Order_Refund $b): int => $a->get_id() <=> $b->get_id()
        );
        foreach ($refunds as $each) {
            if ($each->get_id() > $refund->get_id()) {
                break;
            }
            $given = $this->given($each, $items, $left);
            foreach ($given as $itemId => $amount) {
                $left[$itemId] = $left[$itemId]->minus($amount);
            }
            if ($each->get_id() === $refund->get_id()) {
                $this->amounts = $given;
                $this->allocated = $this->wasAllocated($each, $items);
            }
        }

        return $this;
    }

    /**
     * @param list<KalakaItem> $items
     * @param array<int, Money> $left
     * @return array<int, Money>
     */
    private function given(\WC_Order_Refund $refund, array $items, array $left): array
    {
        $given = [];
        foreach ($refund->get_items() as $line) {
            if (!$line instanceof \WC_Order_Item_Product) {
                continue;
            }
            $of = (int) $line->get_meta('_refunded_item_id', true);
            $amount = abs((float) $line->get_total());
            if ($of > 0 && isset($left[$of]) && $amount > 0) {
                $given[$of] = Money::of(wc_format_decimal((string) $amount), $left[$of]->currency());
            }
        }
        if ($given !== [] || $items === []) {
            return $given;
        }

        // An amount only.
        $rest = Money::of(wc_format_decimal((string) $refund->get_amount()), $items[0]->charged->currency());
        foreach ($items as $item) {
            if (!$rest->isPositive()) {
                break;
            }
            $room = $left[$item->id];
            if (!$room->isPositive()) {
                continue;
            }
            $take = $rest->isGreaterThan($room) ? $room : $rest;
            $given[$item->id] = $take;
            $rest = $rest->minus($take);
        }

        return $given;
    }

    /** @param list<KalakaItem> $items */
    private function wasAllocated(\WC_Order_Refund $refund, array $items): bool
    {
        return count($items) > 1 && $refund->get_items() === [];
    }
}
