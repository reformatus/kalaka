<?php

declare(strict_types=1);

namespace Kalaka\Pricing;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_fixed_prices`.
 *
 * @extends EntityRepository<int, FixedPriceRecord>
 */
final class FixedPriceRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_fixed_prices';
    }

    protected function keyColumns(): array
    {
        return ['event_id'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->eventId,
            'version' => $entity->version,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new FixedPriceRecord(
            (int) $row['event_id'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
