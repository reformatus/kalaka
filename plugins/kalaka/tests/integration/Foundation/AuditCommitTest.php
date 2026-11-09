<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Registrations\Api\RegistrationKey;

/** The production runner commits the audit entry with the work, and writes a failed one after the rollback. */
final class AuditCommitTest extends RealTableTestCase
{
    private function useCase(\Closure $body): AdminProbe
    {
        return new AdminProbe($body, event: 12, targets: [new RegistrationKey(12, '00A0')]);
    }

    /** @return list<string> */
    private function column(\mysqli $connection, string $sql): array
    {
        $result = $connection->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_map(static fn (array $row): string => (string) $row[0], $result->fetch_all());
    }

    private function entriesSeenBy(\mysqli $connection): string
    {
        global $wpdb;

        return implode(',', $this->column(
            $connection,
            "SELECT outcome FROM {$wpdb->prefix}kalaka_audit_entries WHERE correlation_id = '"
                . Correlation::id() . "' ORDER BY id"
        ));
    }

    private function subjectsSeenBy(\mysqli $connection): string
    {
        global $wpdb;

        return implode(',', $this->column(
            $connection,
            "SELECT CONCAT(s.record_type, '/', s.record_key) FROM {$wpdb->prefix}kalaka_audit_subjects s"
            . " JOIN {$wpdb->prefix}kalaka_audit_entries e ON e.id = s.entry_id"
            . " WHERE e.correlation_id = '" . Correlation::id() . "' ORDER BY s.id"
        ));
    }

    public function testAFailedRunLeavesItsFailedEntryButNotItsRow(): void
    {
        global $wpdb;
        $second = $this->secondConnection();
        self::assertSame('', $this->entriesSeenBy($second), 'Earlier tests clean up after themselves.');

        $useCase = $this->useCase(function () use ($wpdb): void {
            $wpdb->insert($this->table, ['label' => 'rolled back']);
            DatabaseError::check($wpdb);
            throw new RuleViolation();
        });

        try {
            (new Runner(new TestClock()))->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RuleViolation) {
        }

        self::assertSame('failed', $this->entriesSeenBy($second));
        self::assertSame('event/12,registration/00A0', $this->subjectsSeenBy($second));
        self::assertSame([], $this->rowsSeenBy($second));
    }

    public function testASuccessfulRunLeavesItsEntryWithItsRow(): void
    {
        global $wpdb;
        $second = $this->secondConnection();
        $seenDuring = null;

        $useCase = $this->useCase(function () use ($wpdb, $second, &$seenDuring): void {
            $wpdb->insert($this->table, ['label' => 'committed']);
            DatabaseError::check($wpdb);
            $seenDuring = $this->entriesSeenBy($second);
        });

        (new Runner(new TestClock()))->run($useCase, [], new SystemActor());

        self::assertSame('', $seenDuring);
        self::assertSame('succeeded', $this->entriesSeenBy($second));
        self::assertSame('event/12,registration/00A0', $this->subjectsSeenBy($second));
        self::assertSame(['committed'], $this->rowsSeenBy($second));
    }
}
