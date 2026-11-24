<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_mail_batches`. A batch is inserted once and never changed.
 *
 * @extends EntityRepository<int, MailBatch>
 */
final class MailBatchRepository extends EntityRepository
{
    /**
     * Inserts a new batch unless its request key is already used in the event (`INSERT IGNORE` on the unique
     * key: a second run with the same key waits for the first, then inserts nothing).
     *
     * @return ?MailBatch the stored batch, or null when the request key was already used
     */
    public function insert(MailBatch $batch, Context $context): ?MailBatch
    {
        return $this->insertUnlessDuplicate($batch, $context);
    }

    public static function subjectOf(MailBatch $batch): Subject
    {
        return new Subject(MailBatch::CAUSE, $batch->eventId, (string) $batch->id);
    }

    protected function table(): string
    {
        return 'kalaka_mail_batches';
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
            'event_id' => $entity->eventId,
            'version' => $entity->version,
            'body_id' => $entity->bodyId,
            'layout' => $entity->layout ? 1 : 0,
            'request_key' => $entity->requestKey,
            'created_at' => Instant::toStorage($entity->createdAt),
            'created_by' => $entity->createdBy,
            'queued' => $entity->queued,
            'skipped' => $entity->skipped,
        ];
    }

    protected function fromRow(array $row): object
    {
        return MailBatch::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf($record);
    }
}
