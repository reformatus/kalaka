<?php

declare(strict_types=1);

namespace Kalaka\Foundation\Periodic;

use Kalaka\Events\Api\Event;
use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Clock;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Registrations\Api\RegistrationKey;

/**
 * The periodic run: the sweep of the work that fell due over time, the retries of the flagged
 * registrations, then the sender. foundation.md, "Deferred work".
 */
final class PeriodicRun
{
    public const LAST_RUN_OPTION = 'kalaka_periodic_run_last';

    /** The windows the run missed: "<rule name>|<event id>" => the window's `since` ('' for none). */
    public const MISSED_OPTION = 'kalaka_periodic_run_missed';

    private const LOCK = 'kalaka_periodic_run';

    private const LOCK_TTL = 900;

    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Clock $clock,
        private readonly PeriodicEvaluation $evaluation,
        private readonly TimeRules $rules,
        private readonly Events $events,
        private readonly EvaluationFailureQuery $failures,
    ) {
    }

    /**
     * @return ?PeriodicRunReport null when the run did not happen: the site is closed or another run holds the lock
     * @throws \Throwable whatever the event or flag queries throw; the run ends and writes no last-run time.
     *         A time rule that throws is isolated to its rule and event; its window is kept for the next run.
     */
    public function run(): ?PeriodicRunReport
    {
        if (!LoadState::current()->isOpen()) {
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

        $kept = $this->missed();
        $missed = [];
        $ruleFailures = 0;
        $due = [];
        foreach ($events as $event) {
            foreach ($this->rules->all() as $rule) {
                $pair = $rule->name() . '|' . $event->id;
                $from = $since;
                if (isset($kept[$pair])) {
                    $from = $kept[$pair] === '' ? null : (self::parseLastRun($kept[$pair]) ?? $since);
                }
                try {
                    $names = $rule->due(new TimeRuleInput($event, $from, $until));
                } catch (\Throwable $e) {
                    $ruleFailures++;
                    $missed[$pair] = $from?->format(self::TIME_FORMAT) ?? '';
                    error_log(
                        'Kaláka: time rule ' . $rule->name() . ' failed for event ' . $event->id . ': '
                        . $e::class . ', correlation ' . Correlation::id()
                    );
                    continue;
                }
                foreach ($names as $key) {
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

        if ($missed === []) {
            delete_option(self::MISSED_OPTION);
        } else {
            update_option(self::MISSED_OPTION, $missed, false);
        }

        return new PeriodicRunReport($evaluated, $failed, $ruleFailures);
    }

    /** @return array<string, string> the kept windows; entries not in the stored shape are ignored */
    private function missed(): array
    {
        $stored = get_option(self::MISSED_OPTION);
        if (!is_array($stored)) {
            return [];
        }

        return array_filter(
            $stored,
            static fn ($since, $pair): bool => is_string($since) && is_string($pair),
            ARRAY_FILTER_USE_BOTH
        );
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

    private static function identity(RegistrationKey $key): string
    {
        return $key->eventId . "\0" . $key->code;
    }
}
