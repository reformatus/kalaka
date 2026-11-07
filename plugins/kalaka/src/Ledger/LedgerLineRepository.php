<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Ledger\Api\LedgerLine;
use Kalaka\Ledger\Api\LineKind;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_ledger_lines`; a line is inserted once and never changed.
 *
 * @extends AppendOnlyRepository<int, LedgerLine>
 */
final class LedgerLineRepository extends AppendOnlyRepository
{
    /** The line a registration already holds under the idempotency key, if any. */
    public function findByKey(RegistrationKey $registration, string $idempotencyKey): ?LedgerLine
    {
        return $this->findWhere(
            'event_id = %d AND code = %s AND idempotency_key = %s',
            [$registration->eventId, $registration->code, $idempotencyKey]
        )[0] ?? null;
    }

    protected function table(): string
    {
        return 'kalaka_ledger_lines';
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
            'kind' => $entity->kind->value,
            'payer' => $entity->payer,
            'amount' => $entity->amount->amount(),
            'currency' => $entity->amount->currency()->value,
            'source_type' => $entity->source->type,
            'source_event_id' => $entity->source->eventId,
            'source_key' => $entity->source->key,
            'idempotency_key' => $entity->idempotencyKey,
            'value_date' => $entity->valueDate === null ? null : Instant::toStorage($entity->valueDate),
            'paid_at' => $entity->paidAt === null ? null : Instant::toStorage($entity->paidAt),
            'recorded_at' => Instant::toStorage($entity->recordedAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        $utc = new \DateTimeZone('UTC');

        return new LedgerLine(
            (int) $row['id'],
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            LineKind::from((string) $row['kind']),
            (string) $row['payer'],
            Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            new Subject(
                (string) $row['source_type'],
                $row['source_event_id'] === null ? null : (int) $row['source_event_id'],
                (string) $row['source_key'],
            ),
            (string) $row['idempotency_key'],
            $row['value_date'] === null ? null : new \DateTimeImmutable($row['value_date'], $utc),
            $row['paid_at'] === null ? null : new \DateTimeImmutable($row['paid_at'], $utc),
            new \DateTimeImmutable((string) $row['recorded_at'], $utc),
        );
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
