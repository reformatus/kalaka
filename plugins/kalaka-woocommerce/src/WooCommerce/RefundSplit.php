<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;

/**
 * Which order items a refund gave back, and how much of each. A refund that names its items gives them as they
 * are; when its amount is more than they add up to, the excess goes to the items in item order. One that names
 * none (an amount only) goes to the one item of a one-item order, and on an order of several is split in item
 * order, each item up to what it has left (its charge less what the refunds before it gave back). The whole
 * refund is recorded, never less than its amount.
 */
final class RefundSplit
{
    /** @var array<int, Money> by item ID */
    public array $amounts = [];

    /** Whether the amount was split over the items by this plugin, on an order of several items. */
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
            [$given, $split] = $this->given($each, $items, $left, $currency);
            foreach ($given as $itemId => $amount) {
                $left[$itemId] = $left[$itemId]->minus($amount);
            }
            if ($each->get_id() === $refund->get_id()) {
                $this->amounts = $given;
                $this->allocated = $split && count($items) > 1;
            }
        }

        return $this;
    }

    /**
     * @param list<KalakaItem> $items
     * @param array<int, Money> $left
     * @return array{array<int, Money>, bool} what each item gave back, and whether this plugin split any of it
     */
    private function given(\WC_Order_Refund $refund, array $items, array $left, Currency $currency): array
    {
        $given = [];
        $named = Money::zero($currency);
        foreach ($refund->get_items() as $line) {
            if (!$line instanceof \WC_Order_Item_Product) {
                continue;
            }
            $of = (int) $line->get_meta('_refunded_item_id', true);
            // The line's total is a decimal text, negative on a refund; read it as text, never as a float.
            $amount = Money::of(ltrim(wc_format_decimal((string) $line->get_total()), '-'), $currency);
            if ($of > 0 && isset($left[$of]) && $amount->isPositive()) {
                $given[$of] = isset($given[$of]) ? $given[$of]->plus($amount) : $amount;
                $named = $named->plus($amount);
            }
        }

        // What the refund's amount has more than the items it names (all of it when it names none) goes to the
        // items in item order, each up to what it has left after what was named.
        $rest = Money::of(ltrim(wc_format_decimal((string) $refund->get_amount()), '-'), $currency)->minus($named);
        $split = false;
        foreach ($items as $item) {
            if (!$rest->isPositive()) {
                break;
            }
            $room = $left[$item->id]->minus($given[$item->id] ?? Money::zero($currency));
            if (!$room->isPositive()) {
                continue;
            }
            $take = $rest->isGreaterThan($room) ? $room : $rest;
            $given[$item->id] = isset($given[$item->id]) ? $given[$item->id]->plus($take) : $take;
            $rest = $rest->minus($take);
            $split = true;
        }

        return [$given, $split];
    }
}
