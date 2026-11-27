<?php

declare(strict_types=1);

namespace Kalaka\Deposits;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;

/**
 * The only writer of `kalaka_deposit_settings`. The key is the event's ID. [UNAPPROVED N830, N835]
 *
 * @extends EntityRepository<int, DepositSettings>
 */
final class DepositSettingsRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_deposit_settings';
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
        return ['event_id' => $key];
    }

    protected function toRow(object $entity): array
    {
        return ['event_id' => $entity->eventId, 'version' => $entity->version, 'amount' => $entity->amount];
    }

    protected function fromRow(array $row): object
    {
        return new DepositSettings((int) $row['event_id'], (string) $row['amount'], (int) $row['version']);
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->eventId);
    }
}
