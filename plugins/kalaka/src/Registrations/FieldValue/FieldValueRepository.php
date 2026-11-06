<?php

declare(strict_types=1);

namespace Kalaka\Registrations\FieldValue;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The only writer of `kalaka_field_values`. A value is recorded in the audit as one item
 * `field:<field key>` on its registration.
 *
 * @extends EntityRepository<array{eventId: int, code: string, fieldKey: string}, FieldValue>
 */
final class FieldValueRepository extends EntityRepository
{
    /** @return list<FieldValue> the values of one registration, in one statement */
    public function forRegistration(RegistrationKey $registration): array
    {
        return $this->findWhere('event_id = %d AND code = %s', [$registration->eventId, $registration->code]);
    }

    protected function table(): string
    {
        return 'kalaka_field_values';
    }

    protected function keyColumns(): array
    {
        return ['event_id', 'code', 'field_key'];
    }

    protected function autoIncrement(): bool
    {
        return false;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['event_id' => $key['eventId'], 'code' => $key['code'], 'field_key' => self::checked($key['fieldKey'])];
    }

    protected function toRow(object $entity): array
    {
        return [
            'event_id' => $entity->registration->eventId,
            'code' => $entity->registration->code,
            'field_key' => self::checked($entity->fieldKey),
            'version' => $entity->version,
            'value' => $entity->value,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new FieldValue(
            new RegistrationKey((int) $row['event_id'], (string) $row['code']),
            (string) $row['field_key'],
            (string) $row['value'],
            (int) $row['version'],
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

    protected function auditField(string $column, object $entity): string
    {
        return $column === 'value' ? 'field:' . $entity->fieldKey : $column;
    }

    /** A field key is 1 to 64 characters. */
    private static function checked(string $fieldKey): string
    {
        $length = mb_strlen($fieldKey, 'UTF-8');
        if ($length < 1 || $length > 64) {
            $error = new SystemFailure(null, new \InvalidArgumentException('A field key is 1 to 64 characters.'));
            throw $error;
        }

        return $fieldKey;
    }
}
