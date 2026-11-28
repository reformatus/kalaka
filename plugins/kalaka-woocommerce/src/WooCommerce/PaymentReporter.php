<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Payments\Api\OnlineItem;
use Kalaka\Payments\Api\OnlinePayments;
use Kalaka\Payments\Api\PaidRequest;
use Kalaka\Payments\Api\RefundedRequest;

/**
 * What WooCommerce tells core: a paid order and a refund become ledger lines through core's `OnlinePayments`,
 * once per order item. The hooks run inside WooCommerce's own payment flow, so nothing may throw into it: a
 * failure is reported to the error log and the paid order is picked up again by the reconcile step.
 */
final class PaymentReporter
{
    public function __construct(private readonly OnlinePayments $online = new OnlinePayments())
    {
    }

    /**
     * The one function behind `woocommerce_payment_complete`, `woocommerce_order_status_processing` and
     * `woocommerce_order_status_completed`, the refund's catch-up and the reconcile step: records the order when
     * it is ours and has its paid date, whatever its status is now (an order with a paid date was paid, also one
     * that was refunded or cancelled since). Recording is idempotent, so all three hooks may fire for one payment.
     *
     * @return bool whether the order is recorded now
     */
    public function recordPaidIfPaid(int $orderId): bool
    {
        try {
            $mine = KalakaOrder::of($orderId);
            if ($mine === null) {
                return false;
            }
            if ($mine->isRecorded()) {
                return true;
            }
            $paidDate = $mine->order->get_date_paid();
            if ($paidDate === null) {
                return false;
            }
            $paidAt = new \DateTimeImmutable('@' . $paidDate->getTimestamp());
            $items = [];
            foreach ($mine->items() as $item) {
                if ($item->charged->isPositive()) {
                    $items[] = new OnlineItem($item->registration, $item->payer, $item->charged, (string) $item->id);
                }
            }
            // The total as decimal text, never a float: any non-zero digit in a non-negative total is money.
            $total = wc_format_decimal((string) $mine->order->get_total());
            if ($items === [] && !str_starts_with($total, '-') && preg_match('/[1-9]/', $total) === 1) {
                // A paid order with money on it but no item this plugin can read (an unknown currency, or item
                // meta that does not parse): never marked recorded, so the reconcile step comes back to it and
                // the log says so each time.
                Boundary::report(
                    'Recording WooCommerce order ' . $orderId,
                    new \RuntimeException('A paid Kaláka order has no readable item')
                );

                return false;
            }
            if ($items !== []) {
                $this->online->recordPaid(
                    new PaidRequest(WooProvider::ID, (string) $orderId, $paidAt, $items)
                );
            }
            $mine->order->update_meta_data(OrderMeta::RECORDED, (string) $paidAt->getTimestamp());
            $mine->order->save_meta_data();

            return true;
        } catch (\Throwable $e) {
            Boundary::report('Recording WooCommerce order ' . $orderId, $e);

            return false;
        }
    }

    /**
     * `woocommerce_order_refunded`: the refund's item amounts become `refund` lines. A refund that names no
     * item is split over the items in item order, up to what each item has left; the report says so, and
     * core writes it on the audit entry.
     */
    public function recordRefund(int $orderId, int $refundId): void
    {
        try {
            $mine = KalakaOrder::of($orderId);
            if ($mine === null) {
                return;
            }
            // A refund of an order whose payment is not recorded yet records the payment first. Whether that
            // worked or not, the refund is recorded: the order's status when the refund event fires is
            // `refunded` after a full refund, and a refund is never held back by it.
            $this->recordPaidIfPaid($orderId);
            $refund = wc_get_order($refundId);
            $paidAt = $refund instanceof \WC_Order_Refund ? $refund->get_date_created() : null;
            if (!$refund instanceof \WC_Order_Refund || $paidAt === null) {
                return;
            }
            $split = (new RefundSplit())->of($mine, $refund);
            $items = [];
            foreach ($mine->items() as $item) {
                $amount = $split->amounts[$item->id] ?? null;
                if ($amount !== null && $amount->isPositive()) {
                    $items[] = new OnlineItem($item->registration, $item->payer, $amount, (string) $item->id);
                }
            }
            if ($items === []) {
                return;
            }
            $this->online->recordRefunded(new RefundedRequest(
                WooProvider::ID,
                (string) $orderId,
                (string) $refundId,
                new \DateTimeImmutable('@' . $paidAt->getTimestamp()),
                $items,
                $split->allocated
            ));
        } catch (\Throwable $e) {
            Boundary::report('Recording WooCommerce refund ' . $refundId, $e);
        }
    }

    /**
     * `woocommerce_order_status_changed`: a recorded order that leaves the paid statuses writes nothing (only a
     * refund reverses money); it is reported to the log so staff can see it.
     */
    public function statusChanged(int $orderId, string $from, string $to): void
    {
        try {
            $mine = KalakaOrder::of($orderId);
            if ($mine !== null && $mine->isRecorded() && in_array($to, ['pending', 'failed', 'cancelled'], true)) {
                Boundary::report(
                    'WooCommerce order ' . $orderId,
                    new \LogicException('A recorded order moved from ' . $from . ' to ' . $to . '; nothing written.')
                );
            }
        } catch (\Throwable $e) {
            Boundary::report('WooCommerce order ' . $orderId, $e);
        }
    }

    /** `woocommerce_trash_order`: as `statusChanged()`, for an order that is trashed after it was recorded. */
    public function trashed(int $orderId): void
    {
        $this->statusChanged($orderId, 'paid', 'trash');
    }
}
