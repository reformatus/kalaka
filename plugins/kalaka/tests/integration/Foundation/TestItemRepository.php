<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Audit\Subject;
use Kalaka\Foundation\Api\Repository\EntityRepository;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\LockLevel;
use Kalaka\Registrations\Api\RegistrationKey;

require_once __DIR__ . '/TestItem.php';

/**
 * A test repository over a table of the test's choosing.
 *
 * @extends EntityRepository<int, TestItem>
 */
final class TestItemRepository extends EntityRepository
{
    public function __construct(private readonly string $tableName, private readonly LockLevel $level = LockLevel::Other)
    {
        parent::__construct();
    }

    public static function create(string $table): void
    {
        global $wpdb;
        $wpdb->query(
            "CREATE TABLE {$wpdb->prefix}{$table} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,"
            . " label VARCHAR(100) NOT NULL DEFAULT '', note VARCHAR(100) NULL, token VARCHAR(100) NOT NULL DEFAULT '',"
            . ' reg_event INT NULL, reg_code VARCHAR(10) NULL, version INT UNSIGNED NOT NULL) ENGINE=InnoDB'
        );
    }

    /** @return list<TestItem> */
    public function byLabel(string $label): array
    {
        return $this->findWhere('label = %s', [$label]);
    }

    /** @return list<TestItem> */
    public function byLabelForUpdate(string $label, Context $context): array
    {
        return $this->findWhere('label = %s', [$label], $context);
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
            'label' => $entity->label,
            'note' => $entity->note,
            'token' => $entity->token,
            'reg_event' => $entity->regEvent,
            'reg_code' => $entity->regCode,
            'version' => $entity->version,
        ];
    }

    protected function fromRow(array $row): object
    {
        return new TestItem(
            (string) $row['label'],
            $row['note'],
            (string) $row['token'],
            $row['reg_event'] === null ? null : (int) $row['reg_event'],
            $row['reg_code'],
            (int) $row['id'],
            (int) $row['version'],
        );
    }

    protected function subject(object $record): Subject
    {
        return new Subject('test_item', null, (string) $record->id);
    }

    protected function secretColumns(): array
    {
        return ['token'];
    }

    protected function registrationOf(object $record): ?RegistrationKey
    {
        return $record->regEvent === null || $record->regCode === null
            ? null
            : new RegistrationKey($record->regEvent, $record->regCode);
    }

    protected function lockLevel(): LockLevel
    {
        return $this->level;
    }
}
