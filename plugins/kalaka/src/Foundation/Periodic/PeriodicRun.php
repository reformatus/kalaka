<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Database\BinlogCheck;
use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Foundation\ErrorLog;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The periodic run: the sweep of the work that fell due over time, the retries of the flagged
 * registrations, then the sender. foundation.md, "Deferred work".
 */
final class PeriodicRun
{
    public const LAST_RUN_OPTION = 'kalaka_periodic_run_last';

    /**
     * The last failure of a run WP-Cron or an admin started: `at` (UTC, in the last-run format) and
     * `error` (the exception chain as the error log writes it). Kept by the cron handler's boundary;
     * the stall warning compares it with the last completed run.
     */
    public const FAILURE_OPTION = 'kalaka_periodic_run_failure';

    private const LOCK = 'kalaka_periodic_run';

    private const LOCK_TTL = 900;

    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Clock $clock,
        private readonly PeriodicEvaluation $evaluation,
        private readonly TimeRules $rules,
        private readonly Events $events,
        private readonly EvaluationFailureQuery $failures,
        private readonly BinlogCheck $binlog = new BinlogCheck(),
    ) {
    }

    /**
     * @return ?PeriodicRunReport null when the run did not happen: the site is closed, the database refuses writes
     *                            (`BinlogCheck`) or another run holds the lock
     * @throws \Throwable whatever a time rule or a query throws; the run ends and writes no last-run time
     */
    public function run(): ?PeriodicRunReport
    {
        if (!LoadState::current()->isOpen() || $this->binlog->refuses()) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (!\WP_Upgrader::create_lock(self::LOCK, self::LOCK_TTL)) {
            return null;
        }

        try {
            $until = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            $report = $this->sweep($this->lastRun(), $until);
            update_option(self::LAST_RUN_OPTION, $until->format(self::TIME_FORMAT), false);

            return $report;
        } finally {
            \WP_Upgrader::release_lock(self::LOCK);
        }
    }

    private function sweep(?\DateTimeImmutable $since, \DateTimeImmutable $until): PeriodicRunReport
    {
        $events = $this->events->published();

        $due = [];
        foreach ($events as $event) {
            foreach ($this->rules->all() as $rule) {
                foreach ($rule->due(new TimeRuleInput($event, $since, $until)) as $key) {
                    $due[self::identity($key)] = $key;
                }
            }
        }
        usort(
            $due,
            static fn (RegistrationKey $a, RegistrationKey $b): int => $a->eventId <=> $b->eventId
                ?: strcmp($a->code, $b->code)
        );

        $done = [];
        $evaluated = 0;
        $failed = 0;
        $evaluate = function (RegistrationKey $key) use (&$done, &$evaluated, &$failed): void {
            if (isset($done[self::identity($key)])) {
                return;
            }
            $done[self::identity($key)] = true;
            if ($this->evaluation->evaluate($key)) {
                $evaluated++;
            } else {
                $failed++;
            }
        };

        foreach ($due as $key) {
            $evaluate($key);
        }
        $eventIds = array_map(static fn (Event $event): int => $event->id, $events);
        foreach ($this->failures->flaggedIn($eventIds) as $key) {
            $evaluate($key);
        }

        return new PeriodicRunReport($evaluated, $failed);
    }

    private function lastRun(): ?\DateTimeImmutable
    {
        $stored = get_option(self::LAST_RUN_OPTION);

        return is_string($stored) ? self::parseLastRun($stored) : null;
    }

    /** The one place that reads the stored last-run time; null when the value is not in the stored format. */
    public static function parseLastRun(string $stored): ?\DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $stored, new \DateTimeZone('UTC')) ?: null;
    }

    /** Keeps a run's failure for the stall warning; only the cron handler calls it. */
    public static function recordFailure(\DateTimeImmutable $at, \Throwable $error): void
    {
        update_option(self::FAILURE_OPTION, [
            'at' => $at->setTimezone(new \DateTimeZone('UTC'))->format(self::TIME_FORMAT),
            'error' => ErrorLog::chain($error),
        ], false);
    }

    /** @return ?array{at: \DateTimeImmutable, error: string} null when none is kept or the value is not in its format */
    public static function lastFailure(): ?array
    {
        $stored = get_option(self::FAILURE_OPTION);
        if (!is_array($stored) || !is_string($stored['at'] ?? null) || !is_string($stored['error'] ?? null)) {
            return null;
        }
        $at = self::parseLastRun($stored['at']);

        return $at === null ? null : ['at' => $at, 'error' => $stored['error']];
    }

    private static function identity(RegistrationKey $key): string
    {
        return $key->eventId . "\0" . $key->code;
    }
}
