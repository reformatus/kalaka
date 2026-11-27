<?php

declare(strict_types=1);

namespace Kalaka\Foundation\ActKeys;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Foundation\Api\Time\Instant;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_act_keys`: append-only, one row per used retry key. It never names the
 * registration as touched: remembering a key changes no registration fact. [UNAPPROVED N1140]
 *
 * @extends AppendOnlyRepository<int, ActKey>
 */
final class ActKeyRepository extends AppendOnlyRepository
{
    public function held(RegistrationKey $registration, string $key): ?ActKey
    {
        $rows = $this->findWhere(
            'event_id = %d AND code = %s AND idempotency_key = %s',
            [$registration->eventId, $registration->code, $key]
        );

        return $rows[0] ?? null;
    }

    protected function table(): string
    {
        return 'kalaka_act_keys';
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
            'act' => $entity->act,
            'idempotency_key' => $entity->key,
            'input_hash' => $entity->inputHash,
            'result' => $entity->result,
            'created_at' => Instant::toStorage($entity->createdAt),
        ];
    }

    protected function fromRow(array $row): object
    {
        return new ActKey(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['act'],
            (string) $row['idempotency_key'],
            (string) $row['input_hash'],
            (string) $row['result'],
            Instant::fromStorage((string) $row['created_at']),
            (int) $row['id'],
        );
    }

    protected function subject(object $record): Subject
    {
        return new Subject('act_key', $record->registration->eventId, (string) $record->id);
    }
}
