<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Foundation\Api\UseCase\LockLevel;

/**
 * The only writer of `kalaka_events`.
 *
 * @extends EntityRepository<int, EventRecord>
 */
final class EventRepository extends EntityRepository
{
    protected function table(): string
    {
        return 'kalaka_events';
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

    private static function instant(?\DateTimeImmutable $time): ?string
    {
        return $time === null ? null : Instant::toStorage($time);
    }

    protected function toRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'version' => $entity->version,
            'name' => $entity->name,
            'state' => $entity->state->value,
            'currency' => $entity->currency->value,
            'capacity' => $entity->capacity,
            'is_full' => $entity->isFull ? 1 : 0,
            'registration_opens_at' => self::instant($entity->registrationOpensAt),
            'registration_closes_at' => self::instant($entity->registrationClosesAt),
            'starts_at' => self::instant($entity->startsAt),
            'ends_at' => self::instant($entity->endsAt),
            'points' => self::encodePoints($entity->points),
            'next_point_id' => $entity->nextPointId,
            'archived_at' => self::instant($entity->archivedAt),
            'transfer_beneficiary' => $entity->transferBeneficiary,
            'transfer_account' => $entity->transferAccount,
            'transfer_reference_prefix' => $entity->transferReferencePrefix,
            'payment_due_days' => $entity->paymentDueDays,
        ];
    }

    protected function fromRow(array $row): object
    {
        return EventRecord::fromRow($row);
    }

    protected function subject(object $record): Subject
    {
        return Subject::event($record->id);
    }

    protected function lockLevel(): LockLevel
    {
        return LockLevel::Event;
    }

    /**
     * The one canonical form: ordered by id, keys id, name, at (UTC), no whitespace.
     *
     * @param list<array{id: int, name: string, at: \DateTimeImmutable}> $points
     */
    private static function encodePoints(array $points): string
    {
        usort($points, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $list = [];
        foreach ($points as $point) {
            $list[] = ['id' => $point['id'], 'name' => $point['name'], 'at' => Instant::toStorage($point['at'])];
        }

        $json = wp_json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $error = new SystemFailure(null, new \LogicException('The custom points are not JSON-encodable.'));
            throw $error;
        }

        return $json;
    }
}
