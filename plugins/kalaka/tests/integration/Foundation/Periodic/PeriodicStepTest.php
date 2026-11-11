<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Periodic;

use Kalaka\Events\Api\Events;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\Periodic\PeriodicStep;
use Kalaka\Foundation\Evaluation\EvaluationFailureQuery;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Foundation\Periodic\PeriodicRun;
use Kalaka\Foundation\Database\BinlogCheck;

/** The modules' periodic steps run at the end of every run, each behind the error boundary. */
final class PeriodicStepTest extends PeriodicTestCase
{
    /** @param list<PeriodicStep> $steps */
    private function runWith(array $steps): PeriodicRun
    {
        $contributions = new Contributions();
        foreach ($steps as $step) {
            $contributions->periodicStep($step);
        }

        return new PeriodicRun(
            $this->clock,
            new PeriodicEvaluation($this->runner),
            $this->timeRules,
            new Events(),
            new EvaluationFailureQuery(),
            new BinlogCheck(),
            static fn (): array => $contributions->periodicSteps()
        );
    }

    private static function step(string $name, \Closure $work): PeriodicStep
    {
        return new class ($name, $work) implements PeriodicStep {
            public function __construct(private readonly string $name, private readonly \Closure $work)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function run(): void
            {
                ($this->work)();
            }
        };
    }

    public function testEveryStepRunsInContributionOrderAndAThrowingOneIsLoggedWithoutEndingTheRun(): void
    {
        $ran = [];
        $steps = [
            self::step('first', static function () use (&$ran): never {
                $ran[] = 'first';
                throw new \RuntimeException('Teszt hiba.');
            }),
            self::step('second', static function () use (&$ran): void {
                $ran[] = 'second';
            }),
        ];

        $log = $this->capturedErrorLog(function () use ($steps, &$report): void {
            $report = $this->runWith($steps)->run();
        });

        self::assertNotNull($report);
        self::assertSame(['first', 'second'], $ran);
        self::assertStringContainsString('Kaláka: periodic step first failed', $log);
        self::assertStringContainsString('RuntimeException (0): Teszt hiba.', $log);
        self::assertIsString(get_option(PeriodicRun::LAST_RUN_OPTION), 'The run completed.');
    }

    public function testTheStepsRunWhenTheSweepThrowsAndTheRunStillEndsWithoutItsLastRunTime(): void
    {
        $this->eventIn('published');
        update_option(PeriodicRun::LAST_RUN_OPTION, '2026-11-30 08:55:00.000000', false);
        $ran = false;
        $throw = static function (string $sql): string {
            if (str_contains($sql, 'kalaka_evaluation_failures')) {
                throw new \RuntimeException('query failed');
            }

            return $sql;
        };
        add_filter('query', $throw);
        try {
            $this->runWith([self::step('sender', static function () use (&$ran): void {
                $ran = true;
            })])->run();
            self::fail('The sweep\'s exception should have left run().');
        } catch (\RuntimeException $e) {
            self::assertSame('query failed', $e->getMessage());
        } finally {
            remove_filter('query', $throw);
        }

        self::assertTrue($ran, 'The step ran although the sweep failed.');
        self::assertSame('2026-11-30 08:55:00.000000', get_option(PeriodicRun::LAST_RUN_OPTION));
    }

    public function testNoStepRunsWhileTheSiteIsClosed(): void
    {
        $ran = false;
        $this->closeTheSite();

        $this->runWith([self::step('step', static function () use (&$ran): void {
            $ran = true;
        })])->run();

        self::assertFalse($ran);
    }
}
