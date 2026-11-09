<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\Entry\Boundary;
use Kalaka\Foundation\ErrorLog;

/** The WP-Cron wiring of the periodic run: the 5-minute interval, the recurring event and the manual start's hook. */
final class PeriodicSchedule
{
    public const INTERVAL = 'kalaka_five_minutes';

    public const HOOK = 'kalaka_periodic_run';

    public const NOW_HOOK = 'kalaka_periodic_run_now';

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * Adds the interval and the handler of both hooks, and schedules the recurring event if it is not.
     * The handler lets no exception escape, so one failing run never aborts the `wp-cron.php` request.
     *
     * @param \Closure(): mixed $run
     */
    public function register(\Closure $run): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules[self::INTERVAL] = ['interval' => 300, 'display' => 'Ötpercenként (Kaláka)'];

            return $schedules;
        });

        $handler = static function () use ($run): void {
            Boundary::background('periodic run', $run);
        };
        add_action(self::HOOK, $handler);
        add_action(self::NOW_HOOK, $handler);

        if (wp_next_scheduled(self::HOOK) === false) {
            $failure = self::failureOf(
                wp_schedule_event($this->clock->now()->getTimestamp(), self::INTERVAL, self::HOOK, [], true)
            );
            if ($failure !== null) {
                // At boot: logged, never thrown, so a scheduling problem does not fail the module's load.
                ErrorLog::failure('scheduling the periodic run', $failure);
            }
        }
    }

    /**
     * What a `wp_schedule_*()` call in its `$wp_error` form failed with, or null on success. An event
     * that is already scheduled (`duplicate_event`) counts as success.
     */
    public static function failureOf(bool|\WP_Error $result): ?\RuntimeException
    {
        if (!$result instanceof \WP_Error || $result->get_error_code() === 'duplicate_event') {
            return null;
        }

        return new \RuntimeException(
            'WP-Cron scheduling failed: ' . $result->get_error_code() . ': ' . $result->get_error_message()
        );
    }

    public static function clear(): void
    {
        wp_unschedule_hook(self::HOOK);
        wp_unschedule_hook(self::NOW_HOOK);
    }
}
