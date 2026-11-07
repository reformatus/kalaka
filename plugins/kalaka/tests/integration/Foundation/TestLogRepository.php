<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\AppendOnlyRepository;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * A test repository of append-only records, with a generated key or a given one.
 *
 * @extends AppendOnlyRepository<int, TestLog>
 */
final class TestLogRepository extends AppendOnlyRepository
{
    public function __construct(private readonly string $tableName, private readonly bool $generated = true)
    {
        parent::__construct();
    }

    public static function create(string $table, bool $generated): void
    {
        global $wpdb;
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- test setup or inspection; the statement is built from the table prefix and constants only
            "CREATE TABLE {$wpdb->prefix}{$table} (id INT UNSIGNED " . ($generated ? 'AUTO_INCREMENT ' : '')
            . "PRIMARY KEY, label VARCHAR(100) NOT NULL DEFAULT '', reg_event INT NULL, reg_code VARCHAR(10) NULL)"
            . ' ENGINE=InnoDB'
        );
    }

    protected function table(): string
    {
        return $this->tableName;
    }

    protected function keyColumns(): array
    {
        return ['id'];
    }

    protected function autoIncrement(): bool
    {
        return $this->generated;
    }

    protected function keyToColumns(mixed $key): array
    {
        return ['id' => (int) $key];
    }

    protected function toRow(object $entity): array
    {
        return [
            'id' => $entity->id,
            'label' => $entity->label,
            'reg_event' => $entity->regEvent,
            'reg_code' => $entity->regCode,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new TestLog(
            (string) $row['label'],
            $row['reg_event'] === null ? null : (int) $row['reg_event'],
            $row['reg_code'],
            (int) $row['id'],
        );
    }

    protected function subject(object $record): Subject
    {
        return new Subject('test_log', null, (string) $record->id);
    }

    protected function registrationOf(object $record): ?RegistrationKey
    {
        return $record->regEvent === null || $record->regCode === null
            ? null
            : new RegistrationKey($record->regEvent, $record->regCode);
    }
}
