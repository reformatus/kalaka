<?php

declare(strict_types=1);

namespace Kalaka\Events;

use Kalaka\Events\Api\EventState;
use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Repository\EntityRepository;
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

    protected function toRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'version' => $entity->version,
            'name' => $entity->name,
            'state' => $entity->state->value,
            'capacity' => $entity->capacity,
            'is_full' => $entity->isFull ? 1 : 0,
            'registration_opens_on' => $entity->registrationOpensOn,
            'registration_closes_on' => $entity->registrationClosesOn,
            'starts_on' => $entity->startsOn,
            'ends_on' => $entity->endsOn,
            'points' => self::encodePoints($entity->points),
            'next_point_id' => $entity->nextPointId,
            'archived_at' => $entity->archivedAt,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new EventRecord(
            (string) $row['name'],
            EventState::from((string) $row['state']),
            $row['capacity'] === null ? null : (int) $row['capacity'],
            $row['is_full'] === '1',
            $row['registration_opens_on'],
            $row['registration_closes_on'],
            $row['starts_on'],
            $row['ends_on'],
            self::decodePoints((string) $row['points']),
            (int) $row['next_point_id'],
            $row['archived_at'],
            (int) $row['id'],
            (int) $row['version'],
        );
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
     * The one canonical form: ordered by id, keys id, name, date, no whitespace.
     *
     * @param list<array{id: int, name: string, date: string}> $points
     */
    private static function encodePoints(array $points): string
    {
        usort($points, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $list = [];
        foreach ($points as $point) {
            $list[] = ['id' => $point['id'], 'name' => $point['name'], 'date' => $point['date']];
        }

        $json = wp_json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $error = new SystemFailure(null, new \LogicException('The custom points are not JSON-encodable.'));
            throw $error;
        }

        return $json;
    }

    /** @return list<array{id: int, name: string, date: string}> */
    private static function decodePoints(string $json): array
    {
        $decoded = json_decode($json, true);
        $points = [];
        foreach (is_array($decoded) ? $decoded : [] as $point) {
            if (
                !is_array($point)
                || !is_int($point['id'] ?? null)
                || !is_string($point['name'] ?? null)
                || !is_string($point['date'] ?? null)
            ) {
                $error = new SystemFailure(null, new \UnexpectedValueException('A stored custom point is malformed.'));
                throw $error;
            }
            $points[] = ['id' => $point['id'], 'name' => $point['name'], 'date' => $point['date']];
        }

        return $points;
    }
}
