<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Events\Api\Events;
use Kalaka\Events\ArchiveEvent;
use Kalaka\Events\CreateEvent;
use Kalaka\Events\PublishEvent;
use Kalaka\Events\UnpublishEvent;
use Kalaka\Events\UpdateEvent;
use Kalaka\Foundation\Api\LoadFailure;
use Kalaka\Foundation\Api\LoadState;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Foundation\Evaluation\Evaluation;
use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\PeriodicSchedule;
use Kalaka\Foundation\Periodic\TimeRules;
use Kalaka\Tests\Integration\Foundation\Evaluation\EvaluationTestCase;

require_once __DIR__ . '/../Evaluation/EvaluationTestCase.php';
require_once __DIR__ . '/SpyTimeRule.php';

/**
 * The base of the periodic run tests: the clock stands at 2026-11-30 10:00 in Europe/Budapest
 * (09:00 UTC), the run is built over the test runner and a registry the test fills, and the
 * production handlers of the cron hooks are gone, so a hook fires only what the test wired.
 */
abstract class PeriodicTestCase extends EvaluationTestCase
{
    protected TimeRules $timeRules;

    /** @var list<LoadFailure> */
    private array $loadFailures = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->timeRules = new TimeRules();
        $this->loadFailures = LoadState::current()->failures;
        remove_all_actions(PeriodicSchedule::HOOK);
        remove_all_actions(PeriodicSchedule::NOW_HOOK);
        wp_unschedule_hook(PeriodicSchedule::HOOK);
        wp_unschedule_hook(PeriodicSchedule::NOW_HOOK);
        delete_option(PeriodicRun::LAST_RUN_OPTION);
    }

    public function tear_down(): void
    {
        LoadState::record($this->loadFailures);

        parent::tear_down();
    }

    protected function useClock(string $local): void
    {
        $this->clock = $this->clockAt($local);
        $this->runner = new Runner($this->clock, true, new Evaluation($this->rules));
    }

    protected function periodicRun(): PeriodicRun
    {
        return new PeriodicRun(
            $this->clock,
            new PeriodicEvaluation($this->runner),
            $this->timeRules,
            new Events(),
            new EvaluationFailureQuery()
        );
    }

    protected function closeTheSite(): void
    {
        LoadState::record([new LoadFailure('x', 'y')]);
    }

    /** An event in the given stored state: `draft`, `published`, `unpublished` or `archived`. */
    protected function eventIn(string $state): int
    {
        $id = $this->exec(new CreateEvent(), ['name' => 'Tábor']);
        self::assertIsInt($id);
        if ($state === 'draft') {
            return $id;
        }

        $this->exec(new UpdateEvent(), [
            'id' => $id,
            'version' => $this->eventVersion($id),
            'name' => 'Tábor',
            'capacity' => 41,
            'registration_opens_on' => '2026-11-30',
            'registration_closes_on' => '2026-12-15',
            'starts_on' => '2027-07-05',
            'ends_on' => '2027-07-12',
            'points' => [],
        ]);
        $steps = match ($state) {
            'published' => [PublishEvent::class],
            'unpublished' => [PublishEvent::class, UnpublishEvent::class],
            default => [PublishEvent::class, UnpublishEvent::class, ArchiveEvent::class],
        };
        foreach ($steps as $useCase) {
            $this->exec(new $useCase(), ['id' => $id, 'version' => $this->eventVersion($id)]);
        }

        return $id;
    }

    private function eventVersion(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT version FROM {$wpdb->prefix}kalaka_events WHERE id = %d", $id)
        );
    }

    /** @return list<array<string, ?string>> the entries of one action */
    protected function entriesOf(string $action): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['action'] === $action
        ));
    }

    /** @return list<array<string, ?string>> the entries of the evaluations and their flags, not those of the events' set-up */
    protected function evaluationEntries(): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => in_array(
                $entry['action'],
                ['kalaka/evaluate-registration', 'kalaka/flag-evaluation-failure'],
                true
            )
        ));
    }

    /**
     * @return list<string> `event/code` of the registration each entry of the action evaluated, in the order written
     */
    protected function evaluatedKeys(string $outcome = 'succeeded'): array
    {
        $keys = [];
        foreach ($this->entriesOf('kalaka/evaluate-registration') as $entry) {
            if ($entry['outcome'] !== $outcome) {
                continue;
            }
            foreach ($this->subjectsOf($entry['id'] ?? 0) as $subject) {
                if (str_starts_with($subject, 'registration/')) {
                    $keys[] = substr($subject, strlen('registration/'));
                }
            }
        }

        return $keys;
    }
}
