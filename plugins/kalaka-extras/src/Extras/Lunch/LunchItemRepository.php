<?php

declare(strict_types=1);

namespace Kalaka\Extras\Lunch;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_extras_lunch_items`.
 *
 * @extends EntityRepository<array{eventId: int, key: string}, LunchItemRecord>
 */
final class LunchItemRepository extends EntityRepository
{
    /**
     * The event's lunch items in order, in one statement; `FOR UPDATE` when a context is given.
     *
     * @return list<LunchItemRecord>
     */
    public function forEvent(int $eventId, ?Context $forUpdate = null): array
    {
        $records = $this->findWhere('event_id = %d', [$eventId], $forUpdate);
        usort(
            $records,
            static fn (LunchItemRecord $a, LunchItemRecord $b): int
                => $a->position <=> $b->position ?: strcmp($a->key, $b->key)
        );

        return $records;
    }

    protected function table(): string
    {
        return 'kalaka_extras_lunch_items';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'item_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'item_key' => $key['key']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'item_key' => $entity->key,
            'version' => $entity->version,
            'position' => $entity->position,
            'label' => $entity->label,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
            'availability' => $entity->condition === null ? null : (string) wp_json_encode($entity->condition),
        ];
    }

    protected function fromRow(array $row): object
    {
        $condition = $row['availability'] === null ? null : json_decode((string) $row['availability'], true);

        return new LunchItemRecord(
            (int) $row['event_id'],
            (string) $row['item_key'],
            (int) $row['position'],
            (string) $row['label'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            is_array($condition) ? $condition : null,
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
