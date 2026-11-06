<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\Actor\SystemActor;
use Kalaka\Foundation\Api\Actor\UserActor;
use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\PermissionDenied;
use Kalaka\Foundation\Periodic\PeriodicSchedule;
use Kalaka\Foundation\Periodic\StartPeriodicRun;

require_once __DIR__ . '/PeriodicTestCase.php';

/** AC9. */
final class StartPeriodicRunTest extends PeriodicTestCase
{
    private function scheduledCount(): int
    {
        $count = 0;
        foreach (_get_cron_array() as $events) {
            $count += isset($events['kalaka_periodic_run_now']) ? count($events['kalaka_periodic_run_now']) : 0;
        }

        return $count;
    }

    public function testAFullAccessUserSchedulesTheSingleEventAtTheClocksNowAndNothingRunsHere(): void
    {
        $this->eventIn('published');
        $spy = SpyTimeRule::naming('00A0');
        $this->timeRules->add($spy);
        (new PeriodicSchedule($this->clock))->register(fn () => $this->periodicRun()->run());
        $entriesBefore = count($this->entries());

        $this->exec(new StartPeriodicRun(), []);

        self::assertSame($this->clock->now()->getTimestamp(), wp_next_scheduled('kalaka_periodic_run_now'));
        $entries = array_slice($this->entries(), $entriesBefore);
        self::assertSame(
            [['kalaka/start-periodic-run', 'succeeded', 'user']],
            array_map(static fn (array $e): array => [$e['action'], $e['outcome'], $e['actor_type']], $entries)
        );
        self::assertSame([], $spy->inputs);
    }

    public function testStartingTwiceLeavesOneScheduledEvent(): void
    {
        $this->exec(new StartPeriodicRun(), []);
        $this->exec(new StartPeriodicRun(), []);

        self::assertSame(1, $this->scheduledCount());
    }

    public function testAUserWithoutFullAccessIsRefusedAndNothingIsScheduled(): void
    {
        $this->expectException(PermissionDenied::class);

        try {
            $this->exec(new StartPeriodicRun(), [], new UserActor(self::factory()->user->create()));
        } finally {
            self::assertFalse(wp_next_scheduled('kalaka_periodic_run_now'));
        }
    }

    public function testTheSystemAndTheCliWithoutAUserMayStartIt(): void
    {
        $this->exec(new StartPeriodicRun(), [], new SystemActor());
        $this->exec(new StartPeriodicRun(), [], new CliActor(null));

        self::assertSame(1, $this->scheduledCount());
    }

    public function testTheInputIsEmpty(): void
    {
        $this->expectException(InvalidInput::class);

        $this->exec(new StartPeriodicRun(), ['x' => 1]);
    }
}
