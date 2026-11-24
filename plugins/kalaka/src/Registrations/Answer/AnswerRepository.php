<?php

declare(strict_types=1);

namespace Kalaka\Registrations\Answer;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\AnswerRead;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_answers`: one row per answering act, inserted once and never changed or replaced
 * (an upsert would give a changed answer no key of its own). A write marks the registration touched, so the
 * evaluation runs after it.
 *
 * @extends AppendOnlyRepository<int, AnswerRead>
 */
final class AnswerRepository extends AppendOnlyRepository
{
    /**
     * The registration's answering acts, oldest first, in one statement.
     *
     * @return list<AnswerRead>
     */
    public function forRegistration(RegistrationKey $registration): array
    {
        return $this->findWhere(
            'event_id = %d AND code = %s ORDER BY id',
            [$registration->eventId, $registration->code]
        );
    }

    protected function table(): string
    {
        return 'kalaka_answers';
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
            'request' => $entity->request,
            'approved' => $entity->approved ? 1 : 0,
            'amount' => $entity->amount?->amount(),
            'currency' => $entity->amount?->currency()->value,
            'note' => $entity->note,
            'answered_at' => Instant::toStorage($entity->answeredAt),
            'answered_by' => $entity->answeredBy,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new AnswerRead(
            (int) $row['id'],
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['request'],
            (int) $row['approved'] === 1,
            $row['amount'] === null
                ? null
                : Money::of((string) $row['amount'], Currency::from((string) $row['currency'])),
            $row['note'] === null ? null : (string) $row['note'],
            Instant::fromStorage((string) $row['answered_at']),
            $row['answered_by'] === null ? null : (int) $row['answered_by'],
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
