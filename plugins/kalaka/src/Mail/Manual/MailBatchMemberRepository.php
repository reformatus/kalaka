<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;

/**
 * The only writer of `kalaka_mail_batch_members`: inserted with the batch, never changed. Its audit subject
 * is the batch's, so a thousand members add no subject of their own.
 *
 * @extends AppendOnlyRepository<array{batchId: int, code: string}, MailBatchMember>
 */
final class MailBatchMemberRepository extends AppendOnlyRepository
{
    protected function table(): string
    {
        return 'kalaka_mail_batch_members';
    }

    protected function keyColumns(): array
    {
        return ['batch_id', 'code'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['batch_id' => $key['batchId'], 'code' => $key['code']];
    }

    protected function toRow(object $entity): array
    {
        return [
            'batch_id' => $entity->batchId,
            'code' => $entity->code,
            'event_id' => $entity->eventId,
            'outcome' => $entity->outcome,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new MailBatchMember(
            (int) $row['batch_id'],
            (int) $row['event_id'],
            (string) $row['code'],
            (string) $row['outcome']
        );
    }

    protected function subject(object $record): Subject
    {
        return new Subject(MailBatch::CAUSE, $record->eventId, (string) $record->batchId);
    }
}
