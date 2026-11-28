<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;
use Kalaka\Foundation\Api\UseCase\Runner;

/**
 * Picks up what a failed hook left: the last 30 days' paid orders of ours that no report has recorded are
 * recorded now, through the same idempotent function the hooks call, and so is every refund made in those
 * 30 days (the day the refund was made, not the order). A paid order still unrecorded an hour
 * after it was paid is reported to the error log on every run (a site warning for it waits for an approved
 * text).
 */
final class ReconcileStep implements PeriodicStep
{
    private const DAYS = 30;

    private const GRACE = 3600;

    public function __construct(
        private readonly OrderFinder $finder = new OrderFinder(),
        private readonly PaymentReporter $reporter = new PaymentReporter(),
    ) {
    }

    public function name(): string
    {
        return 'woocommerce_reconcile';
    }

    public function run(): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }
        $now = Runner::current()->clock()->now();
        $since = $now->modify('-' . self::DAYS . ' days');
        foreach ($this->finder->unrecordedPaidSince($since) as $order) {
            if ($this->reporter->recordPaidIfPaid($order->get_id())) {
                continue;
            }
            $paid = $order->get_date_paid();
            if ($paid !== null && $now->getTimestamp() - $paid->getTimestamp() > self::GRACE) {
                Boundary::report(
                    'WooCommerce reconcile',
                    new \RuntimeException('Order ' . $order->get_id() . ' is paid and still not recorded.')
                );
            }
        }
        // Every refund made in the window, again: recording is idempotent by the refund and item, so one whose
        // recording failed earlier is written now and the rest write nothing.
        foreach ($this->finder->refundsSince($since) as $refund) {
            $this->reporter->recordRefund($refund->get_parent_id(), $refund->get_id());
        }
    }
}
