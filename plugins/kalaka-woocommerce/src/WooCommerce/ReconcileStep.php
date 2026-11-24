<?php

declare(strict_types=1);

namespace Kalaka\WooCommerce;

use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;

/**
 * Picks up what a failed hook left: the last 30 days' paid orders of ours that no report has recorded are
 * recorded now, through the same idempotent function the hooks call. A paid order still unrecorded an hour
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
        $now = time();
        $since = new \DateTimeImmutable('@' . ($now - self::DAYS * DAY_IN_SECONDS));
        foreach ($this->finder->unrecordedPaidSince($since) as $order) {
            if ($this->reporter->recordPaidIfPaid($order->get_id())) {
                continue;
            }
            $paid = $order->get_date_paid();
            if ($paid !== null && $now - $paid->getTimestamp() > self::GRACE) {
                Boundary::report(
                    'WooCommerce reconcile',
                    new \RuntimeException('Order ' . $order->get_id() . ' is paid and still not recorded.')
                );
            }
        }
    }
}
