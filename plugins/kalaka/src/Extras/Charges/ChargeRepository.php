<?php

declare(strict_types=1);

namespace Kalaka\Extras\Charges;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_extras_charges`.
 *
 * @extends EntityRepository<array{eventId: int, key: string}, ChargeRecord>
 */
final class ChargeRepository extends EntityRepository
{
    /**
     * The event's charges in order, in one statement; `FOR UPDATE` when a context is given.
     *
     * @return list<ChargeRecord>
     */
    public function forEvent(int $eventId, ?Context $forUpdate = null): array
    {
        $records = $this->findWhere('event_id = %d', [$eventId], $forUpdate);
        usort(
            $records,
            static fn (ChargeRecord $a, ChargeRecord $b): int
                => $a->position <=> $b->position ?: strcmp($a->key, $b->key)
        );

        return $records;
    }

    protected function table(): string
    {
        return 'kalaka_extras_charges';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'charge_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'charge_key' => $key['key']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'charge_key' => $entity->key,
            'version' => $entity->version,
            'position' => $entity->position,
            'label' => $entity->label,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new ChargeRecord(
            (int) $row['event_id'],
            (string) $row['charge_key'],
            (int) $row['position'],
            (string) $row['label'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
