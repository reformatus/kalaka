<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\Delivery;
use Kalaka\Mail\DeliveryQuery;
use Kalaka\Mail\Manual\MailBatch;

/**
 * The deliveries of an event that need a look, for the event's mail page and `wp kalaka mail failed`'s
 * source (mail.md, "Failures"): failed for want of a valid address, pending with an error, or left in
 * "sending". It lists registration codes only; the screen resolves a name through the registration's own read.
 * Full access only reads it: the caller checks.
 */
final class Deliveries
{
    public function __construct(private readonly DeliveryQuery $deliveries = new DeliveryQuery())
    {
    }

    /**
     * One event's troubled deliveries in queue order: one statement.
     *
     * @return list<TroubledDelivery>
     */
    public function troubled(int $eventId): array
    {
        return array_map(static fn (Delivery $delivery): TroubledDelivery => new TroubledDelivery(
            $delivery->id,
            $delivery->registration->code,
            $delivery->mail,
            ActionMail::tryFrom($delivery->mail),
            $delivery->kind,
            $delivery->status->value,
            $delivery->attempts,
            $delivery->queuedAt,
            $delivery->claimedAt,
            $delivery->error,
            $delivery->causeType === MailBatch::CAUSE ? (int) $delivery->causeKey : null
        ), $this->deliveries->troubled($eventId));
    }
}
