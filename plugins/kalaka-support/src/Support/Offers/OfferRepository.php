<?php

declare(strict_types=1);

namespace Kalaka\Support\Offers;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

/**
 * The only writer of `kalaka_support_offers`. An offer's row is its seat pool's own row, so it sits at the quota
 * lock level, before the registration rows (foundation.md, "Lock order").
 *
 * @extends EntityRepository<array{eventId: int, key: string}, OfferRecord>
 */
final class OfferRepository extends EntityRepository
{
    /**
     * The event's offers in order, in one statement; `FOR UPDATE` when a context is given.
     *
     * @return list<OfferRecord>
     */
    public function forEvent(int $eventId, ?Context $forUpdate = null): array
    {
        $records = $this->findWhere('event_id = %d', [$eventId], $forUpdate);
        usort(
            $records,
            static fn (OfferRecord $a, OfferRecord $b): int
                => $a->position <=> $b->position ?: strcmp($a->key, $b->key)
        );

        return $records;
    }

    protected function table(): string
    {
        return 'kalaka_support_offers';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'offer_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function lockLevel(): LockLevel
    {
        return LockLevel::Quota;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'offer_key' => $key['key']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'offer_key' => $entity->key,
            'version' => $entity->version,
            'position' => $entity->position,
            'label' => $entity->label,
            'node_id' => $entity->nodeId,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
            'needs_request' => $entity->needsRequest ? 1 : 0,
            'seat_limit' => $entity->limit,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new OfferRecord(
            (int) $row['event_id'],
            (string) $row['offer_key'],
            (int) $row['position'],
            (string) $row['label'],
            (int) $row['node_id'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            $row['needs_request'] === '1',
            $row['seat_limit'] === null ? null : (int) $row['seat_limit'],
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
