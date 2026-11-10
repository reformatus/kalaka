<?php

declare(strict_types=1);

namespace Kalaka\Mail\Api;

use Kalaka\Mail\DeliveryQuery;
use Kalaka\Mail\DeliveryStatus;
use Kalaka\Mail\Pause\MailStateQuery;

/**
 * The mail counts for the admin dashboard ("Levelek", and the mail rows of "Összesítés" and "Teendők").
 * Counts only, with no addresses and no error text; shown to full access only. mail.md, "Failures":
 * staff see which deliveries carry an error, failed, or were left in "sending".
 * // [UNAPPROVED S8-4] the read model of the mail counts (the dashboard's seam).
 */
final class MailStats
{
    public function __construct(
        private readonly DeliveryQuery $deliveries = new DeliveryQuery(),
        private readonly MailStateQuery $state = new MailStateQuery(),
    ) {
    }

    /** Site-wide: the pause and the pending deliveries of every event. Two statements. */
    public function site(): SiteMailStats
    {
        $state = $this->state->current();
        $paused = null;
        if ($state->pausedAt !== null && $state->cause !== null) {
            $paused = new PauseInfo($state->cause->value, $state->pausedBy, $state->pausedAt);
        }

        return new SiteMailStats($paused, $this->deliveries->pendingCount());
    }

    /**
     * Per event, in one statement that stays inside the events asked for.
     *
     * @param list<int> $eventIds
     * @return array<int, EventMailStats> one per asked event, keyed by its id; zeros for an event without deliveries
     */
    public function ofEvents(array $eventIds): array
    {
        $counts = [];
        foreach ($eventIds as $eventId) {
            $counts[$eventId] = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'withError' => 0, 'stuckSending' => 0];
        }
        foreach ($this->deliveries->countsOf(array_values(array_unique($eventIds))) as $row) {
            $event = $row['event'];
            if (!isset($counts[$event])) {
                continue;
            }
            $field = match ($row['status']) {
                DeliveryStatus::Pending => 'queued',
                DeliveryStatus::Sending => 'stuckSending',
                DeliveryStatus::Sent => 'sent',
                DeliveryStatus::Failed => 'failed',
            };
            $counts[$event][$field] += $row['count'];
            if ($row['status'] === DeliveryStatus::Pending && $row['withError']) {
                $counts[$event]['withError'] += $row['count'];
            }
        }

        $stats = [];
        foreach ($counts as $event => $count) {
            $stats[$event] = new EventMailStats(
                $count['queued'],
                $count['sent'],
                $count['failed'],
                $count['withError'],
                $count['stuckSending'],
            );
        }

        return $stats;
    }
}
