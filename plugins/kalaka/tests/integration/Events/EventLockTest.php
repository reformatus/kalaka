<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Events;

use Kalaka\Events\Api\EventCapacity;
use Kalaka\Events\CreateEvent;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\ObjectCache;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\RealTableTestCase;
use Kalaka\Tests\Integration\Foundation\TestClock;

require_once __DIR__ . '/../Foundation/RealTableTestCase.php';

/** AC10: a second connection cannot lock the event row while the use case holds it. */
final class EventLockTest extends RealTableTestCase
{
    private int $eventId = 0;

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        parent::tear_down();

        if ($this->eventId !== 0) {
            $wpdb->delete($wpdb->prefix . 'kalaka_events', ['id' => $this->eventId]);
            $wpdb->query('COMMIT');
        }
        ObjectCache::clear();
    }

    public function testTheEventRowIsLockedForOtherConnectionsUntilTheUseCaseEnds(): void
    {
        global $wpdb;
        ObjectCache::clear();
        $runner = new Runner(new TestClock(), true);
        $this->eventId = (int) $runner->run(new CreateEvent(), ['name' => 'Tábor'], new CliActor(null));
        $wpdb->query('COMMIT');

        $second = $this->secondConnection();
        $second->query('SET SESSION innodb_lock_wait_timeout = 1');
        $second->begin_transaction();
        $sql = "SELECT id FROM {$wpdb->prefix}kalaka_events WHERE id = {$this->eventId} FOR UPDATE";

        $wpdb->query('START TRANSACTION');
        $code = null;
        $runner->run(
            new AdminProbe(function (array $input, Context $context) use ($second, $sql, &$code): void {
                (new EventCapacity())->lockForRegistration($context, $this->eventId);
                try {
                    $second->query($sql);
                    $code = $second->errno;
                } catch (\mysqli_sql_exception $error) {
                    $code = $error->getCode();
                }
            }),
            [],
            new CliActor(null)
        );
        $wpdb->query('COMMIT');

        self::assertSame(1205, $code);
        self::assertNotFalse($second->query($sql));
    }
}
