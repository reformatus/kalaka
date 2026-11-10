<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Foundation\Api\UseCase\AdminUseCase;
use Kalaka\Foundation\Api\UseCase\Context;

/**
 * Asks for a periodic run: it writes nothing and, after commit, schedules a single WP-Cron event, so the
 * run happens in the next `wp-cron.php` request and never in this one.
 */
final class StartPeriodicRun implements AdminUseCase
{
    public static function name(): string
    {
        return 'kalaka/start-periodic-run';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function action(): ?string
    {
        return null;
    }

    public function event(array $input): ?int
    {
        return null;
    }

    public function targets(array $input): array
    {
        return [];
    }

    public function execute(array $input, Context $context): null
    {
        $timestamp = $context->now()->getTimestamp();
        $context->afterCommit(static function () use ($timestamp): void {
            // An error other than an already scheduled run is thrown, so the runner logs it.
            $failure = PeriodicSchedule::failureOf(
                wp_schedule_single_event($timestamp, PeriodicSchedule::NOW_HOOK, [], true)
            );
            if ($failure !== null) {
                throw $failure;
            }
        });

        return null;
    }
}
