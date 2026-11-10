<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\PeriodicSchedule;

/** AC1, AC2 and the WP-Cron handler of AC4. */
final class PeriodicScheduleTest extends PeriodicTestCase
{
    private function schedule(): PeriodicSchedule
    {
        return new PeriodicSchedule($this->clock);
    }

    private function scheduledCount(string $hook): int
    {
        $count = 0;
        foreach (_get_cron_array() as $events) {
            $count += isset($events[$hook]) ? count($events[$hook]) : 0;
        }

        return $count;
    }

    public function testBootAddsTheFiveMinuteIntervalAndSchedulesTheRunAtTheClocksNow(): void
    {
        $this->schedule()->register(static fn (): null => null);

        $schedules = wp_get_schedules();
        self::assertSame(300, $schedules['kalaka_five_minutes']['interval']);
        self::assertSame('Ötpercenként (Kaláka)', $schedules['kalaka_five_minutes']['display']);
        self::assertSame($this->clock->now()->getTimestamp(), wp_next_scheduled('kalaka_periodic_run'));
        $event = wp_get_scheduled_event('kalaka_periodic_run');
        self::assertIsObject($event);
        self::assertSame('kalaka_five_minutes', $event->schedule);
        self::assertSame(300, $event->interval);
        self::assertSame([], $event->args);
    }

    public function testBootingAgainSchedulesNoSecondEvent(): void
    {
        $this->schedule()->register(static fn (): null => null);
        $this->useClock('2026-11-30 10:03:00');
        $this->schedule()->register(static fn (): null => null);

        self::assertSame(1, $this->scheduledCount('kalaka_periodic_run'));
        self::assertSame(
            $this->clockAt('2026-11-30 10:00:00')->now()->getTimestamp(),
            wp_next_scheduled('kalaka_periodic_run')
        );
    }

    public function testDeactivatingThePluginClearsBothHooks(): void
    {
        $this->schedule()->register(static fn (): null => null);
        wp_schedule_single_event($this->clock->now()->getTimestamp(), 'kalaka_periodic_run_now');
        self::assertNotFalse(wp_next_scheduled('kalaka_periodic_run_now'));

        do_action('deactivate_' . plugin_basename(KALAKA_FILE));

        self::assertFalse(wp_next_scheduled('kalaka_periodic_run'));
        self::assertFalse(wp_next_scheduled('kalaka_periodic_run_now'));
    }

    public function testEachHookRunsThePeriodicRunOnce(): void
    {
        $this->eventIn('published');
        $spy = SpyTimeRule::naming();
        $this->timeRules->add($spy);
        $this->schedule()->register(fn () => $this->periodicRun()->run());

        do_action('kalaka_periodic_run');
        self::assertCount(1, $spy->inputs);

        do_action('kalaka_periodic_run_now');
        self::assertCount(2, $spy->inputs);
    }

    public function testAThrowingRunIsLoggedWithItsChainAndTheHandlerReturns(): void
    {
        $this->eventIn('published');
        // A failing time rule is isolated to its rule and event (#41), so the run fails on the flag query instead.
        $throw = static function (string $query): string {
            if (str_contains($query, 'kalaka_evaluation_failures')) {
                throw new \RuntimeException('secret rule message');
            }

            return $query;
        };
        $this->schedule()->register(fn () => $this->periodicRun()->run());

        foreach (['kalaka_periodic_run', 'kalaka_periodic_run_now'] as $hook) {
            add_filter('query', $throw);
            try {
                $log = $this->capturedErrorLog(static function () use ($hook): void {
                    do_action($hook);
                });
            } finally {
                remove_filter('query', $throw);
            }

            // The whole chain, the message included (foundation.md, "The error log").
            self::assertSame(
                'Kaláka: periodic run failed, correlation ' . Correlation::id() . ': ' . \RuntimeException::class
                . ' (0): secret rule message',
                trim(preg_replace('/^\[[^\]]*\] /', '', $log) ?? '')
            );
            self::assertFalse(get_option('kalaka_periodic_run.lock'));
            self::assertFalse(get_option(PeriodicRun::LAST_RUN_OPTION));
        }
    }

    public function testAThrowingRunKeepsItsTimeAndErrorChainForTheStallWarning(): void
    {
        $error = new \RuntimeException('outer', 0, new \LogicException('inner'));
        $this->schedule()->register(static function () use ($error): never {
            throw $error;
        });

        $this->capturedErrorLog(static function (): void {
            do_action('kalaka_periodic_run');
        });

        self::assertSame(
            [
                'at' => '2026-11-30 09:00:00.000000',
                'error' => 'RuntimeException (0): outer <- LogicException (0): inner',
            ],
            get_option(PeriodicRun::FAILURE_OPTION)
        );
        $failure = PeriodicRun::lastFailure();
        self::assertNotNull($failure);
        self::assertSame('2026-11-30 09:00:00', $failure['at']->format('Y-m-d H:i:s'));
    }

    public function testACompletedRunKeepsNoFailure(): void
    {
        $this->schedule()->register(fn () => $this->periodicRun()->run());

        do_action('kalaka_periodic_run');

        self::assertFalse(get_option(PeriodicRun::FAILURE_OPTION));
        self::assertNull(PeriodicRun::lastFailure());
    }

    public function testASchedulingErrorAtBootIsLoggedAndNotThrown(): void
    {
        add_filter('pre_schedule_event', static fn (): \WP_Error => new \WP_Error('test_failure', 'cron is broken'));

        $log = $this->capturedErrorLog(function (): void {
            $this->schedule()->register(static fn (): null => null);
        });

        self::assertSame(
            'Kaláka: scheduling the periodic run failed, correlation ' . Correlation::id()
            . ': RuntimeException (0): WP-Cron scheduling failed: test_failure: cron is broken',
            trim(preg_replace('/^\[[^\]]*\] /', '', $log) ?? '')
        );
        self::assertFalse(wp_next_scheduled('kalaka_periodic_run'));
    }
}
