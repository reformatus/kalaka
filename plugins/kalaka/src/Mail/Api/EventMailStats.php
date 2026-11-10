<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

/** One event's deliveries counted by where they stand. Counts only: no addresses, no error text. */
final readonly class EventMailStats
{
    /**
     * @param int $queued pending, those carrying an error included; while the event is not published these
     *                    are its held deliveries, which `kalaka/clear-event-mail` drops
     * @param int $failed never attempted: no valid address
     * @param int $withError pending ones that carry the error of an earlier attempt
     * @param int $stuckSending left in "sending" by a crash (or being sent right now)
     */
    public function __construct(
        public int $queued = 0,
        public int $sent = 0,
        public int $failed = 0,
        public int $withError = 0,
        public int $stuckSending = 0,
    ) {
    }
}
