<?php

declare(strict_types=1);

namespace Kalaka\PriceLadder;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;

/**
 * The only writer of `kalaka_price_ladder_tiers`.
 *
 * @extends EntityRepository<array{eventId: int, key: string}, TierRecord>
 */
final class TierRepository extends EntityRepository
{
    /**
     * The event's tiers in ladder order, in one statement; `FOR UPDATE` when a context is given.
     *
     * @return list<TierRecord>
     */
    public function forEvent(int $eventId, ?Context $forUpdate = null): array
    {
        $records = $this->findWhere('event_id = %d', [$eventId], $forUpdate);
        usort(
            $records,
            static fn (TierRecord $a, TierRecord $b): int => $a->position <=> $b->position ?: strcmp($a->key, $b->key)
        );

        return $records;
    }

    /**
     * A tier is a quota row: a limited tier is a seat pool, locked before the registration rows
     * (foundation.md, "Lock order").
     */
    protected function lockLevel(): LockLevel
    {
        return LockLevel::Quota;
    }

    protected function table(): string
    {
        return 'kalaka_price_ladder_tiers';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'tier_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'tier_key' => $key['key']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'tier_key' => $entity->key,
            'version' => $entity->version,
            'position' => $entity->position,
            'label' => $entity->label,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
            'seat_limit' => $entity->limit,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new TierRecord(
            (int) $row['event_id'],
            (string) $row['tier_key'],
            (int) $row['position'],
            (string) $row['label'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            (int) $row['version'],
            $row['seat_limit'] === null ? null : (int) $row['seat_limit'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
