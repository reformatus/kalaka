<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation;

use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Database\DatabaseError;
use Kalaka\Foundation\Api\Error\RuleViolation;
use Kalaka\Foundation\Api\UseCase\Runner;

require_once __DIR__ . '/RealTableTestCase.php';

/** The production runner (no savepoint option) really commits and really rolls back. */
final class RunnerCommitTest extends RealTableTestCase
{
    public function testASuccessfulRunIsVisibleToAnotherConnectionAfterwards(): void
    {
        global $wpdb;
        $second = $this->secondConnection();
        $seenDuring = null;

        $useCase = new AdminProbe(function () use ($wpdb, $second, &$seenDuring): string {
            $wpdb->insert($this->table, ['label' => 'committed']);
            DatabaseError::check($wpdb);
            $seenDuring = $this->rowsSeenBy($second);

            return 'done';
        });

        $result = (new Runner(new TestClock()))->run($useCase, [], new SystemActor());

        self::assertSame('done', $result);
        self::assertSame([], $seenDuring);
        self::assertSame(['committed'], $this->rowsSeenBy($second));
    }

    public function testAFailedRunIsNeverVisibleToAnotherConnection(): void
    {
        global $wpdb;
        $second = $this->secondConnection();
        $seenDuring = null;

        $useCase = new AdminProbe(function () use ($wpdb, $second, &$seenDuring): void {
            $wpdb->insert($this->table, ['label' => 'rolled back']);
            DatabaseError::check($wpdb);
            $seenDuring = $this->rowsSeenBy($second);
            throw new RuleViolation();
        });

        try {
            (new Runner(new TestClock()))->run($useCase, [], new SystemActor());
            self::fail('The run should have thrown.');
        } catch (RuleViolation) {
            self::assertSame([], $seenDuring);
            self::assertSame([], $this->rowsSeenBy($second));
        }
    }
}
