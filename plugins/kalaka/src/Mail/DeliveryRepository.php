<?php

declare(strict_types=1);

namespace Kalaka\Mail;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * The only writer of `kalaka_deliveries`. A delivery maps to no registration lock, so writing one never
 * marks a registration touched.
 *
 * @extends EntityRepository<int, Delivery>
 */
final class DeliveryRepository extends EntityRepository
{
    /**
     * Inserts a new delivery unless its once-only key is taken (`INSERT IGNORE`).
     * // [UNAPPROVED S8-9] INSERT IGNORE instead of find-for-update and insert.
     *
     * @return ?Delivery the stored delivery, or null when the key was already queued
     */
    public function queue(Delivery $delivery, Context $context): ?Delivery
    {
        return $this->insertUnlessDuplicate($delivery, $context);
    }

    /**
     * Every pending delivery, read `FOR UPDATE`, in id order.
     *
     * @return list<Delivery>
     */
    public function pendingForUpdate(Context $context): array
    {
        return $this->findWhere('status = %s ORDER BY id', [DeliveryStatus::Pending->value], $context);
    }

    protected function table(): string
    {
        return 'kalaka_deliveries';
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
            'mail' => $entity->mail,
            'code' => $entity->registration->code,
            'cause_type' => $entity->causeType,
            'cause_key' => $entity->causeKey,
            'version' => $entity->version,
            'kind' => $entity->kind->value,
            'status' => $entity->status->value,
            'attempts' => $entity->attempts,
            'queued_at' => Instant::toStorage($entity->queuedAt),
            'claimed_at' => $entity->claimedAt === null ? null : Instant::toStorage($entity->claimedAt),
            'sent_at' => $entity->sentAt === null ? null : Instant::toStorage($entity->sentAt),
            'mail_version' => $entity->mailVersion,
            'error' => $entity->error,
        ];
    }

    protected function fromRow(array $row): object
    {
        return Delivery::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return self::subjectOf($record);
    }

    public static function subjectOf(Delivery $delivery): Subject
    {
        return new Subject('delivery', $delivery->registration->eventId, (string) $delivery->id);
    }
}
