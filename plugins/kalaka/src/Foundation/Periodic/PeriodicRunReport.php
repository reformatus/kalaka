<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

/** What a periodic run did: the registrations it evaluated, those whose evaluation failed and were flagged,
 * the (time rule, event) checks that threw, and the registrations skipped for a retryable
 * failure (not flagged, left for the next run). */
final readonly class PeriodicRunReport
{
    public function __construct(
        public int $evaluated,
        public int $failed,
        public int $timeRulesFailed,
        public int $skipped = 0,
    ) {
    }
}
