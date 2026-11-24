<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Ledger\Api\ManualFee;
use Kalaka\Ledger\Api\ManualFeeType;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_manual_fees`. A row is inserted once; the only update is the erase, which sets
 * the title to null. The title is free text, so it never reaches the audit entry (`secretColumns()`).
 *
 * @extends EntityRepository<int, ManualFee>
 */
final class ManualFeeRepository extends EntityRepository
{
    /**
     * The registration's entries, oldest first; a plain read, or `FOR UPDATE` given a context.
     *
     * @return list<ManualFee>
     */
    public function forRegistration(RegistrationKey $registration, ?Context $forUpdate = null): array
    {
        return $this->findWhere(
            'event_id = %d AND code = %s ORDER BY id',
            [$registration->eventId, $registration->code],
            $forUpdate
        );
    }

    /** The entry the registration already holds under the idempotency key, if any. */
    public function findByKey(RegistrationKey $registration, string $idempotencyKey): ?ManualFee
    {
        return $this->findWhere(
            'event_id = %d AND code = %s AND idempotency_key = %s',
            [$registration->eventId, $registration->code, $idempotencyKey]
        )[0] ?? null;
    }

    protected function table(): string
    {
        return 'kalaka_manual_fees';
    }

    protected function keyColumns(): array
    {
        return ['id'];
    }

    protected function autoIncrement(): bool
    {
        return true;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'type' => $entity->type->value,
            'payer' => $entity->payer,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
            'title' => $entity->title,
            'idempotency_key' => $entity->idempotencyKey,
            'recorded_by' => $entity->recordedBy,
            'recorded_at' => Instant::toStorage($entity->recordedAt),
            'version' => $entity->version,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new ManualFee(
            (int) $row['id'],
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            ManualFeeType::from((string) $row['type']),
            (string) $row['payer'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            $row['title'] === null ? null : (string) $row['title'],
            (string) $row['idempotency_key'],
            $row['recorded_by'] === null ? null : (int) $row['recorded_by'],
            Instant::fromStorage((string) $row['recorded_at']),
            (int) $row['version'],
        );
    }

    protected function secretColumns(): array
    {
        return ['title'];
    }

    protected function subject(object $record): Subject
    {
        return Subject::registration($record->registration);
    }

    protected function registrationOf(object $record): RegistrationKey
    {
        return $record->registration;
    }
}
