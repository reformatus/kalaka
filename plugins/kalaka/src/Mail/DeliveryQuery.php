<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Query\Query;
use Kalaka\Foundation\Api\Time\Instant;

/**
 * Reads the deliveries: the sender's candidates and its claim counts, and the counts of `MailStats`.
 * Nothing else reads the table (mail.md, "Words").
 */
final class DeliveryQuery extends Query
{
    /**
     * One statement on the `event_status` index. A pending delivery that already had an attempt (its
     * attempt ended in an error) comes first; then self-sent and action mails, then manual, reminder and
     * scheduled ones, each tier in queue order. mail.md, "Sending" and "Failures".
     *
     * @param list<int> $eventIds the events whose deliveries may be sent
     * @return list<Delivery> at most `$limit`
     */
    public function candidates(array $eventIds, int $limit): array
    {
        if ($eventIds === [] || $limit < 1) {
            return [];
        }

        return $this->scope(function () use ($eventIds, $limit): array {
            $placeholders = implode(', ', array_fill(0, count($eventIds), '%d'));
            $rows = $this->select(
                'delivery',
                'SELECT * FROM %i WHERE event_id IN (' . $placeholders . ') AND status = %s'
                . ' ORDER BY attempts > 0 DESC,'
                . " CASE kind WHEN 'self_sent' THEN 1 WHEN 'action' THEN 1 WHEN 'manual' THEN 2"
                . " WHEN 'reminder' THEN 3 ELSE 4 END, id LIMIT %d",
                [$this->table('kalaka_deliveries'), ...$eventIds, ...[DeliveryStatus::Pending->value, $limit]]
            );

            return array_map(Delivery::fromRow(...), $rows);
        });
    }

    /**
     * The claims of an hour, split at the start of the current slot. One statement on the `claimed_at`
     * index. A retried delivery's earlier claim is overwritten by its new one, so it no longer counts.
     *
     * @return array{before: int, current: int} the claims in `[$hourStart, $slotStart)` and in `[$slotStart, $slotEnd)`
     */
    public function claimCounts(
        \DateTimeImmutable $hourStart,
        \DateTimeImmutable $slotStart,
        \DateTimeImmutable $slotEnd,
    ): array {
        return $this->scope(function () use ($hourStart, $slotStart, $slotEnd): array {
            $slot = Instant::toStorage($slotStart);
            $rows = $this->select(
                'count',
                'SELECT COALESCE(SUM(claimed_at < %s), 0) AS before_slot, COALESCE(SUM(claimed_at >= %s), 0) AS in_slot'
                . ' FROM %i WHERE claimed_at >= %s AND claimed_at < %s',
                [
                    $slot,
                    $slot,
                    $this->table('kalaka_deliveries'),
                    Instant::toStorage($hourStart),
                    Instant::toStorage($slotEnd),
                ]
            );

            return ['before' => (int) ($rows[0]['before_slot'] ?? 0), 'current' => (int) ($rows[0]['in_slot'] ?? 0)];
        });
    }

    /** The pending deliveries of the whole site, in one statement. */
    public function pendingCount(): int
    {
        return $this->scope(function (): int {
            $rows = $this->select(
                'count',
                'SELECT COUNT(*) AS pending FROM %i WHERE status = %s',
                [$this->table('kalaka_deliveries'), DeliveryStatus::Pending->value]
            );

            return (int) ($rows[0]['pending'] ?? 0);
        });
    }

    /**
     * The counts per event, status and whether an error is kept, in one statement on the `event_status`
     * index.
     *
     * @param list<int> $eventIds
     * @return list<array{event: int, status: DeliveryStatus, withError: bool, count: int}>
     */
    public function countsOf(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return $this->scope(function () use ($eventIds): array {
            $placeholders = implode(', ', array_fill(0, count($eventIds), '%d'));
            $rows = $this->select(
                'count',
                'SELECT event_id, status, error IS NOT NULL AS with_error, COUNT(*) AS deliveries FROM %i'
                . ' WHERE event_id IN (' . $placeholders . ') GROUP BY event_id, status, with_error',
                [$this->table('kalaka_deliveries'), ...$eventIds]
            );

            $counts = [];
            foreach ($rows as $row) {
                $status = DeliveryStatus::tryFrom((string) $row['status']);
                if ($status === null) {
                    continue;
                }
                $counts[] = [
                    'event' => (int) $row['event_id'],
                    'status' => $status,
                    'withError' => $row['with_error'] === '1',
                    'count' => (int) $row['deliveries'],
                ];
            }

            return $counts;
        });
    }

    /**
     * The ids of the events that have any delivery, ascending, in one statement.
     *
     * @return list<int>
     */
    public function eventIds(): array
    {
        return $this->scope(function (): array {
            $rows = $this->select(
                'event_ids',
                'SELECT DISTINCT event_id FROM %i ORDER BY event_id',
                [$this->table('kalaka_deliveries')]
            );

            return array_map(static fn (array $row): int => (int) $row['event_id'], $rows);
        });
    }

    /**
     * One event's deliveries that need a look, in queue order: failed (no valid address), pending with
     * the error of an earlier attempt, and left in "sending". mail.md, "Failures".
     *
     * @return list<Delivery>
     */
    public function troubled(int $eventId): array
    {
        return $this->scope(function () use ($eventId): array {
            $rows = $this->select(
                'delivery',
                'SELECT * FROM %i WHERE event_id = %d AND (status IN (%s, %s) OR (status = %s AND error IS NOT NULL))'
                . ' ORDER BY id',
                [
                    $this->table('kalaka_deliveries'),
                    $eventId,
                    DeliveryStatus::Failed->value,
                    DeliveryStatus::Sending->value,
                    DeliveryStatus::Pending->value,
                ]
            );

            return array_map(Delivery::fromRow(...), $rows);
        });
    }
}
