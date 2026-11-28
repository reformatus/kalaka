<?php

declare(strict_types=1);

namespace Kalaka\Ledger;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_payment_notes`. The note is free text, so it never reaches the audit entry
 * (`secretColumns()`); only the fact that a note was written or erased does.
 *
 * @extends EntityRepository<array{eventId: int, lineId: int}, PaymentNote>
 */
final class PaymentNoteRepository extends EntityRepository
{
    /**
     * The notes of the registration, by line ID; a plain read, or `FOR UPDATE` given a context.
     *
     * @return list<PaymentNote>
     */
    public function forRegistration(RegistrationKey $registration, ?Context $forUpdate = null): array
    {
        return $this->findWhere(
            'event_id = %d AND code = %s ORDER BY line_id',
            [$registration->eventId, $registration->code],
            $forUpdate
        );
    }

    protected function table(): string
    {
        return 'kalaka_payment_notes';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'line_id'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'line_id' => $key['lineId']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->registration->eventId,
            'line_id' => $entity->lineId,
            'code' => $entity->registration->code,
            'version' => $entity->version,
            'note' => $entity->note,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new PaymentNote(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (int) $row['line_id'],
            (string) $row['note'],
            (int) $row['version'],
        );
    }

    protected function secretColumns(): array
    {
        return ['note'];
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
