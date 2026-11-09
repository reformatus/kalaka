<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Api\Error\RetryableFailure;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\PeriodicRunReport;
use Kalaka\Foundation\Periodic\TimeRuleInput;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\FakeBinlog;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;

/** AC3 to AC8 of the periodic run, and the isolation of a failing time rule. */
final class PeriodicRunTest extends PeriodicTestCase
{
    private const LOCK = 'kalaka_periodic_run.lock';

    private function sweep(): ?PeriodicRunReport
    {
        return $this->periodicRun()->run();
    }

    /** @return array{since: ?string, until: string, zone: string} */
    private static function windowOf(TimeRuleInput $input): array
    {
        return [
            'since' => $input->since?->format('Y-m-d H:i:s'),
            'until' => $input->until->format('Y-m-d H:i:s'),
            'zone' => $input->until->getTimezone()->getName() . ($input->since?->getTimezone()->getName() ?? '+'),
        ];
    }

    public function testOnlyPublishedEventsAreSweptAndEachDistinctKeyIsEvaluatedOnceInOrder(): void
    {
        $one = $this->eventIn('published');
        $this->eventIn('unpublished');
        $this->eventIn('archived');
        $this->eventIn('draft');
        $five = $this->eventIn('published');
        $first = SpyTimeRule::naming('00B0', '00A0');
        $second = new SpyTimeRule(static fn (TimeRuleInput $input): array => [
            new RegistrationKey($input->event->id, '00A0'),
            new RegistrationKey($input->event->id, '00A0'),
            new RegistrationKey($input->event->id, '00B6'),
        ]);
        $this->timeRules->add($first);
        $this->timeRules->add($second);

        $report = $this->sweep();

        self::assertSame([$one, $five], $first->askedEvents());
        self::assertSame([$one, $five], $second->askedEvents());
        $window = ['since' => null, 'until' => '2026-11-30 09:00:00', 'zone' => 'UTC+'];
        self::assertSame([$window, $window], array_map(self::windowOf(...), $first->inputs));
        self::assertSame(
            ["$one/00A0", "$one/00B0", "$one/00B6", "$five/00A0", "$five/00B0", "$five/00B6"],
            $this->evaluatedKeys()
        );
        foreach ($this->entriesOf('kalaka/evaluate-registration') as $entry) {
            self::assertSame(['succeeded', 'system'], [$entry['outcome'], $entry['actor_type']]);
        }
        self::assertEquals(new PeriodicRunReport(6, 0, 0), $report);
    }

    public function testTheWindowStartsAtTheLastRunAndTheTimeIsWrittenAfterEachRun(): void
    {
        $this->eventIn('published');
        $spy = SpyTimeRule::naming();
        $this->timeRules->add($spy);

        $this->sweep();

        self::assertSame('2026-11-30 09:00:00.000000', get_option('kalaka_periodic_run_last'));
        global $wpdb;
        self::assertNotContains(
            $wpdb->get_var("SELECT autoload FROM {$wpdb->options} WHERE option_name = 'kalaka_periodic_run_last'"),
            wp_autoload_values_to_autoload()
        );

        $this->useClock('2026-11-30 10:05:00');
        $this->sweep();

        self::assertSame(
            [
                ['since' => null, 'until' => '2026-11-30 09:00:00', 'zone' => 'UTC+'],
                ['since' => '2026-11-30 09:00:00', 'until' => '2026-11-30 09:05:00', 'zone' => 'UTCUTC'],
            ],
            array_map(self::windowOf(...), $spy->inputs)
        );
        self::assertSame('2026-11-30 09:05:00.000000', get_option('kalaka_periodic_run_last'));
    }

    /** A rule that throws for the given event only while $failing[0] is true. */
    private function ruleFailingFor(int $eventId, array &$failing, string $name = 'rule-a'): SpyTimeRule
    {
        return new SpyTimeRule(static function (TimeRuleInput $input) use ($eventId, &$failing): array {
            if ($failing[0] && $input->event->id === $eventId) {
                throw new \RuntimeException('secret message');
            }

            return [new RegistrationKey($input->event->id, '00E2')];
        }, $name);
    }

    public function testATimeRuleThatThrowsForOneEventDoesNotEndTheRun(): void
    {
        $e1 = $this->eventIn('published');
        $e2 = $this->eventIn('published');
        $this->seedFlag($e2, '00F0');
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $failing = [true];
        $a = $this->ruleFailingFor($e1, $failing);
        $b = SpyTimeRule::naming('00A0');
        $this->timeRules->add($a);
        $this->timeRules->add($b);

        $log = $this->capturedErrorLog(function () use (&$report): void {
            $report = $this->sweep();
        });

        self::assertSame([$e1, $e2], $a->askedEvents());
        self::assertSame([$e1, $e2], $b->askedEvents());
        self::assertSame(["$e1/00A0", "$e2/00A0", "$e2/00E2", "$e2/00F0"], $this->evaluatedKeys());
        self::assertEquals(new PeriodicRunReport(4, 0, 1), $report);
        self::assertSame('2026-11-30 09:00:00.000000', get_option('kalaka_periodic_run_last'));
        self::assertFalse(get_option(self::LOCK));
        self::assertSame([], $this->flagRows());
        self::assertSame(1, substr_count($log, 'time rule rule-a failed'));
        self::assertStringContainsString("time rule rule-a failed for event $e1: RuntimeException, correlation ", $log);
        self::assertStringNotContainsString('secret message', $log);
    }

    public function testAMissedWindowIsAskedFromItsSinceInTheNextRunAndThenForgotten(): void
    {
        $e1 = $this->eventIn('published');
        $e2 = $this->eventIn('published');
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $failing = [true];
        $a = $this->ruleFailingFor($e1, $failing);
        $this->timeRules->add($a);

        $this->sweep();

        self::assertSame(["rule-a|$e1" => '2026-11-30 08:55:00.000000'], get_option(PeriodicRun::MISSED_OPTION));

        $failing[0] = false;
        $this->useClock('2026-11-30 10:05:00');
        $this->sweep();

        $windows = array_map(self::windowOf(...), array_slice($a->inputs, 2));
        self::assertSame('2026-11-30 08:55:00', $windows[0]['since']);
        self::assertSame('2026-11-30 09:00:00', $windows[1]['since']);
        self::assertSame('2026-11-30 09:05:00', $windows[0]['until']);
        self::assertSame([$e1, $e2], array_slice($a->askedEvents(), 2));
        self::assertFalse(get_option(PeriodicRun::MISSED_OPTION));
    }

    public function testAPairThatFailsAgainKeepsTheEarliestSince(): void
    {
        $e1 = $this->eventIn('published');
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $failing = [true];
        $a = $this->ruleFailingFor($e1, $failing);
        $this->timeRules->add($a);

        $this->sweep();
        $this->useClock('2026-11-30 10:05:00');
        $this->sweep();

        self::assertSame(["rule-a|$e1" => '2026-11-30 08:55:00.000000'], get_option(PeriodicRun::MISSED_OPTION));

        $failing[0] = false;
        $this->useClock('2026-11-30 10:10:00');
        $this->sweep();

        self::assertSame('2026-11-30 08:55:00', self::windowOf($a->inputs[2])['since']);
        self::assertFalse(get_option(PeriodicRun::MISSED_OPTION));
    }

    public function testAMissedWindowWithNoSinceIsKeptAsAnEmptyString(): void
    {
        $e1 = $this->eventIn('published');
        $failing = [true];
        $a = $this->ruleFailingFor($e1, $failing);
        $this->timeRules->add($a);

        $this->sweep();
        $failing[0] = false;
        $this->useClock('2026-11-30 10:05:00');
        $this->sweep();

        // The second run asks again from the kept empty window, not from the first run's last-run time.
        self::assertNull($a->inputs[1]->since);
    }

    public function testAMissedWindowOfAnUnpublishedEventOrAnUnknownRuleIsDropped(): void
    {
        $e1 = $this->eventIn('published');
        $gone = $this->eventIn('unpublished');
        update_option(PeriodicRun::MISSED_OPTION, [
            "rule-a|$gone" => '2026-11-30 08:00:00.000000',
            "rule-x|$e1" => '2026-11-30 08:00:00.000000',
        ], false);
        $a = SpyTimeRule::naming();
        $this->timeRules->add(new SpyTimeRule(static fn (): array => [], 'rule-a'));
        $this->timeRules->add($a);

        $this->sweep();

        self::assertSame([$e1], $a->askedEvents());
        self::assertNull($a->inputs[0]->since);
        self::assertFalse(get_option(PeriodicRun::MISSED_OPTION));
    }

    public function testATimeRuleNameCanBeRegisteredOnlyOnce(): void
    {
        $this->timeRules->add(new SpyTimeRule(static fn (): array => [], 'late-fee'));

        $this->expectException(\LogicException::class);
        $this->timeRules->add(new SpyTimeRule(static fn (): array => [], 'late-fee'));
    }

    public function testAFailingFlagQueryStillEndsTheRunWithoutTheLastRunTime(): void
    {
        $this->eventIn('published');
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $throw = static function (string $sql): string {
            if (str_contains($sql, 'kalaka_evaluation_failures')) {
                throw new \RuntimeException('query failed');
            }

            return $sql;
        };
        add_filter('query', $throw);
        try {
            $this->sweep();
            self::fail('The exception should have left run().');
        } catch (\RuntimeException $e) {
            self::assertSame('query failed', $e->getMessage());
        } finally {
            remove_filter('query', $throw);
        }

        self::assertSame('2026-11-30 08:55:00.000000', get_option('kalaka_periodic_run_last'));
        self::assertFalse(get_option(PeriodicRun::MISSED_OPTION));
        self::assertFalse(get_option(self::LOCK));
    }

    public function testAFailedEvaluationIsFlaggedAndTheRunGoesOnToTheNextKey(): void
    {
        $event = $this->eventIn('published');
        $rule = new TestRule('test_state');
        $rule->onDecide = static function ($input) use ($rule): void {
            $rule->throws = $input->registration->code === '00A0' ? new \RuntimeException('rule failed') : null;
        };
        $this->rules->add($rule, true);
        $this->timeRules->add(SpyTimeRule::naming('00A0', '00B0'));
        $this->seedRegistration($event, '00A0', '00B0');

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(1, 1, 0), $report);
        self::assertSame(["$event/00A0"], $this->evaluatedKeys('failed'));
        self::assertSame(["$event/00B0"], $this->evaluatedKeys());
        self::assertSame(['00A0'], array_column($this->flagRows(), 'code'));
    }

    public function testOnlyFlagsInPublishedEventsAreRetriedAndASuccessClearsTheFlag(): void
    {
        $published = $this->eventIn('published');
        $unpublished = $this->eventIn('unpublished');
        $this->seedFlag($published, '00A0');
        $this->seedFlag($unpublished, '00B0');

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(1, 0, 0), $report);
        self::assertSame(["$published/00A0"], $this->evaluatedKeys());
        self::assertSame([(string) $unpublished], array_column($this->flagRows(), 'event_id'));
        self::assertSame(['00B0'], array_column($this->flagRows(), 'code'));
    }

    public function testARetryableFailureIsSkippedNotFlaggedAndTheOthersAreEvaluated(): void
    {
        $event = $this->eventIn('published');
        $this->rules->add(new TestRule('test_state'), true);
        $this->timeRules->add(SpyTimeRule::naming('00A0', '00B0', '00E2'));
        $this->seedFlag($event, '00E5');
        // The held row of a registration: its first write fails the way a lock wait timeout does.
        $throw = static function (string $query): string {
            if (preg_match('/^\s*INSERT\b.*kalaka_audit/is', $query) === 1 && str_contains($query, '00A0')) {
                throw new RetryableFailure();
            }

            return $query;
        };
        add_filter('query', $throw);
        try {
            $report = $this->sweep();
        } finally {
            remove_filter('query', $throw);
        }

        self::assertEquals(new PeriodicRunReport(3, 0, 0, 1), $report);
        self::assertSame(["$event/00B0", "$event/00E2", "$event/00E5"], $this->evaluatedKeys());
        self::assertSame([], $this->flagRows());
        self::assertSame([], $this->evaluatedKeys('failed'));
        // The skipped registration's window is kept, so the next run asks its time rule again from the same since.
        $missed = get_option(PeriodicRun::MISSED_OPTION);
        self::assertIsArray($missed);
        self::assertSame([''], array_values($missed));
        self::assertStringEndsWith("|$event", (string) array_key_first($missed));

        // The hold ends: the next run evaluates the skipped registration again.
        $this->useClock('2026-11-30 10:05:00');
        $next = $this->sweep();

        self::assertContains("$event/00A0", $this->evaluatedKeys());
        self::assertSame(0, $next->skipped);
        self::assertFalse(get_option(PeriodicRun::MISSED_OPTION));
    }

    public function testAFlagThatFailsAgainIsReplaced(): void
    {
        $published = $this->eventIn('published');
        $this->seedFlag($published, '00A0');
        $this->seedRegistration($published, '00A0');
        $rule = new TestRule('test_state');
        $rule->throws = new \RuntimeException('rule failed');
        $this->rules->add($rule, true);

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(0, 1, 0), $report);
        $rows = $this->flagRows();
        self::assertCount(1, $rows);
        self::assertSame(
            ['decisions/test_state', '2026-11-30 09:00:00.000000'],
            [$rows[0]['rule'], $rows[0]['failed_at']]
        );
    }

    public function testAKeyThatIsBothDueAndFlaggedIsEvaluatedOnce(): void
    {
        $event = $this->eventIn('published');
        $this->seedFlag($event, '00A0');
        $this->timeRules->add(SpyTimeRule::naming('00A0'));

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(1, 0, 0), $report);
        self::assertSame(["$event/00A0"], $this->evaluatedKeys());
        self::assertSame([], $this->flagRows());
    }

    public function testAKeyThatIsDueAndFailsIsNotRetriedInTheSameRun(): void
    {
        $event = $this->eventIn('published');
        $rule = new TestRule('test_state');
        $rule->throws = new \RuntimeException('rule failed');
        $this->rules->add($rule, true);
        $this->timeRules->add(SpyTimeRule::naming('00A0'));
        $this->seedRegistration($event, '00A0');

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(0, 1, 0), $report);
        self::assertSame(["$event/00A0"], $this->evaluatedKeys('failed'));
        self::assertCount(1, $rule->inputs);
    }

    public function testAFreshLockMakesTheRunSkipAndAStaleOneIsTakenOver(): void
    {
        $this->eventIn('published');
        $spy = SpyTimeRule::naming('00A0');
        $this->timeRules->add($spy);
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        self::assertTrue(\WP_Upgrader::create_lock('kalaka_periodic_run', 900));
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);

        self::assertNull($this->sweep());

        self::assertSame([], $spy->inputs);
        self::assertSame([], $this->evaluationEntries());
        self::assertSame('2026-11-30 08:55:00.000000', get_option('kalaka_periodic_run_last'));
        self::assertNotFalse(get_option(self::LOCK));

        update_option(self::LOCK, time() - 901);

        self::assertNotNull($this->sweep());

        self::assertCount(1, $spy->inputs);
        self::assertFalse(get_option(self::LOCK));
    }

    public function testTheLockIsReleasedAfterACompletedRun(): void
    {
        $this->sweep();

        self::assertFalse(get_option(self::LOCK));
        self::assertNotNull($this->sweep());
    }

    public function testWhileTheSiteIsClosedNothingHappens(): void
    {
        $event = $this->eventIn('published');
        $this->seedFlag($event, '00A0');
        $spy = SpyTimeRule::naming('00A0');
        $this->timeRules->add($spy);
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $this->closeTheSite();

        $statements = $this->statementsDuring(function (): void {
            self::assertNull($this->sweep());
        });

        self::assertSame([], $spy->inputs);
        self::assertSame([], $this->evaluationEntries());
        self::assertSame(['00A0'], array_column($this->flagRows(), 'code'));
        self::assertSame('2026-11-30 08:55:00.000000', get_option('kalaka_periodic_run_last'));
        self::assertSame(
            [],
            array_filter($statements, static fn (string $sql): bool => str_contains($sql, self::LOCK))
        );
    }

    public function testWhileTheDatabaseRefusesWritesNothingHappens(): void
    {
        $event = $this->eventIn('published');
        $this->seedFlag($event, '00A0');
        $spy = SpyTimeRule::naming('00A0');
        $this->timeRules->add($spy);
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $fake = FakeBinlog::statementFormat();

        $statements = $this->statementsDuring(function (): void {
            self::assertNull($this->sweep());
        });

        self::assertSame(1, $fake->reads);
        self::assertSame([], $spy->inputs);
        self::assertSame([], $this->evaluationEntries());
        self::assertSame(['00A0'], array_column($this->flagRows(), 'code'));
        self::assertSame('2026-11-30 08:55:00.000000', get_option('kalaka_periodic_run_last'));
        self::assertSame(
            [],
            array_filter($statements, static fn (string $sql): bool => str_contains($sql, self::LOCK))
        );
    }
}
