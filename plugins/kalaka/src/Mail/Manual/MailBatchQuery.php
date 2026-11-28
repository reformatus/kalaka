<?php

declare(strict_types=1);

namespace Kalaka\Mail\Manual;

use Kalaka\Foundation\Api\Query\Query;

/**
 * Reads the send batches and their members: the composer reads the batch a manual delivery came from, the
 * send use case its repeat, the command line the list. Never the deliveries (mail.md, "Words").
 */
final class MailBatchQuery extends Query
{
    /** @return ?MailBatch the batch of the event with that ID; null for any other */
    public function find(int $eventId, int $id): ?MailBatch
    {
        return $this->scope(function () use ($eventId, $id): ?MailBatch {
            $rows = $this->select(
                'batch',
                'SELECT * FROM %i WHERE event_id = %d AND id = %d',
                [$this->table('kalaka_mail_batches'), $eventId, $id]
            );

            return $rows === [] ? null : MailBatch::fromRow($rows[0]);
        });
    }

    /** @return ?MailBatch the event's batch sent with that request key */
    public function ofRequestKey(int $eventId, string $requestKey): ?MailBatch
    {
        return $this->scope(function () use ($eventId, $requestKey): ?MailBatch {
            $rows = $this->select(
                'batch',
                'SELECT * FROM %i WHERE event_id = %d AND request_key = %s',
                [$this->table('kalaka_mail_batches'), $eventId, $requestKey]
            );

            return $rows === [] ? null : MailBatch::fromRow($rows[0]);
        });
    }

    /**
     * The event's batches, newest first.
     *
     * @return list<MailBatch>
     */
    public function ofEvent(int $eventId): array
    {
        return $this->scope(function () use ($eventId): array {
            $rows = $this->select(
                'batch',
                'SELECT * FROM %i WHERE event_id = %d ORDER BY id DESC',
                [$this->table('kalaka_mail_batches'), $eventId]
            );

            return array_map(MailBatch::fromRow(...), $rows);
        });
    }

    /**
     * A batch's members in code order, as the batch recorded them.
     *
     * @return list<MailBatchMember>
     */
    public function members(int $batchId): array
    {
        return $this->scope(function () use ($batchId): array {
            $rows = $this->select(
                'member',
                'SELECT * FROM %i WHERE batch_id = %d ORDER BY code',
                [$this->table('kalaka_mail_batch_members'), $batchId]
            );

            return array_map(static fn (array $row): MailBatchMember => new MailBatchMember(
                (int) $row['batch_id'],
                (int) $row['event_id'],
                (string) $row['code'],
                (string) $row['outcome']
            ), $rows);
        });
    }
}
