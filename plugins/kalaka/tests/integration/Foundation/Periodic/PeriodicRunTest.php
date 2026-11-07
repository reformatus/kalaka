<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Periodic\PeriodicRunReport;
use Kalaka\Foundation\Periodic\TimeRuleInput;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\Evaluation\TestRule;

/** AC3 to AC8. */
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
            new RegistrationKey($input->event->id, '00C0'),
        ]);
        $this->timeRules->add($first);
        $this->timeRules->add($second);

        $report = $this->sweep();

        self::assertSame([$one, $five], $first->askedEvents());
        self::assertSame([$one, $five], $second->askedEvents());
        $window = ['since' => null, 'until' => '2026-11-30 09:00:00', 'zone' => 'UTC+'];
        self::assertSame([$window, $window], array_map(self::windowOf(...), $first->inputs));
        self::assertSame(
            ["$one/00A0", "$one/00B0", "$one/00C0", "$five/00A0", "$five/00B0", "$five/00C0"],
            $this->evaluatedKeys()
        );
        foreach ($this->entriesOf('kalaka/evaluate-registration') as $entry) {
            self::assertSame(['succeeded', 'system'], [$entry['outcome'], $entry['actor_type']]);
        }
        self::assertEquals(new PeriodicRunReport(6, 0), $report);
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

    public function testATimeRuleThatThrowsEndsTheRunAndLeavesTheLastRunTime(): void
    {
        $this->eventIn('published');
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $this->timeRules->add(new SpyTimeRule(static function (): array {
            throw new \RuntimeException('rule failed');
        }));
        $later = SpyTimeRule::naming('00A0');
        $this->timeRules->add($later);

        try {
            $this->sweep();
            self::fail('The exception should have left run().');
        } catch (\RuntimeException $e) {
            self::assertSame('rule failed', $e->getMessage());
        }

        self::assertSame([], $later->inputs);
        self::assertSame([], $this->evaluationEntries());
        self::assertSame('2026-11-30 08:55:00.000000', get_option('kalaka_periodic_run_last'));
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

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(1, 1), $report);
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

        self::assertEquals(new PeriodicRunReport(1, 0), $report);
        self::assertSame(["$published/00A0"], $this->evaluatedKeys());
        self::assertSame([(string) $unpublished], array_column($this->flagRows(), 'event_id'));
        self::assertSame(['00B0'], array_column($this->flagRows(), 'code'));
    }

    public function testAFlagThatFailsAgainIsReplaced(): void
    {
        $published = $this->eventIn('published');
        $this->seedFlag($published, '00A0');
        $rule = new TestRule('test_state');
        $rule->throws = new \RuntimeException('rule failed');
        $this->rules->add($rule, true);

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(0, 1), $report);
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

        self::assertEquals(new PeriodicRunReport(1, 0), $report);
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

        $report = $this->sweep();

        self::assertEquals(new PeriodicRunReport(0, 1), $report);
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
}
