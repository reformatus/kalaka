<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\Audit\Correlation;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Evaluation\PeriodicEvaluation;
use Kalaka\Foundation\Evaluation\RuleFailed;
use Kalaka\Registrations\Api\RegistrationKey;

require_once __DIR__ . '/EvaluationTestCase.php';

/** The periodic path: one registration per transaction, and the "evaluation failed" flag. */
final class PeriodicEvaluationTest extends EvaluationTestCase
{
    private RegistrationKey $key;

    public function set_up(): void
    {
        parent::set_up();

        $this->key = new RegistrationKey(12, '00A0');
    }

    private function periodic(): PeriodicEvaluation
    {
        return new PeriodicEvaluation($this->runner);
    }

    public function testARunThatLeavesEverythingUnchangedSucceedsAndWritesNothingElse(): void
    {
        $this->rules->add(new TestRule('test_state'), true);

        self::assertTrue($this->periodic()->evaluate($this->key));

        self::assertSame([], $this->decisionRows());
        self::assertSame([], $this->flagRows());
        $entry = $this->onlyEntry();
        self::assertSame(['kalaka/evaluate-registration', 'succeeded', 'system'], [
            $entry['action'],
            $entry['outcome'],
            $entry['actor_type'],
        ]);
        self::assertSame(['event/12/12', 'registration/12/00A0'], $this->subjectsOf($entry['id'] ?? 0));
    }

    public function testAFailureIsKeptOnTheRegistrationAndTheNextOneReplacesIt(): void
    {
        $earlier = new TestRule('earlier');
        $earlier->answer = 'on';
        $rule = new TestRule('test_state');
        $rule->throws = new \RuntimeException('secret rule message');
        $other = new TestRule('other');
        $this->rules->add($earlier, true);
        $this->rules->add($rule, true);
        $this->rules->add($other, true);

        self::assertFalse($this->periodic()->evaluate($this->key));

        self::assertSame([], $this->decisionRows());
        $rows = $this->flagRows();
        self::assertCount(1, $rows);
        self::assertSame(['12', '00A0', 'decisions/test_state', '1'], [
            $rows[0]['event_id'],
            $rows[0]['code'],
            $rows[0]['rule'],
            $rows[0]['version'],
        ]);
        self::assertSame('2026-11-30 09:00:00.000000', $rows[0]['failed_at']);
        $error = $this->decode($rows[0]['error']);
        self::assertSame('kalaka/evaluate-registration', $error['use_case']);
        self::assertSame(SystemFailure::class, $error['class']);
        self::assertSame([RuleFailed::class, \RuntimeException::class], array_column($error['previous'], 'class'));
        self::assertStringNotContainsString('secret rule message', $rows[0]['error'] ?? '');

        $entries = $this->entries();
        self::assertSame(
            [['kalaka/evaluate-registration', 'failed'], ['kalaka/flag-evaluation-failure', 'succeeded']],
            array_map(static fn (array $entry): array => [$entry['action'], $entry['outcome']], $entries)
        );
        self::assertContains('registration/12/00A0', $this->subjectsOf($entries[1]['id'] ?? 0));
        $changes = $this->decode($entries[1]['changes']);
        self::assertSame(['rule', 'error', 'failed_at'], array_column($changes, 'field'));
        self::assertSame(['registration', 12, '00A0'], [$changes[0]['type'], $changes[0]['event'], $changes[0]['key']]);

        $rule->throws = null;
        $other->throws = new \LogicException('another secret');
        $this->useClock('2026-11-30 10:05:00');

        self::assertFalse($this->periodic()->evaluate($this->key));

        $rows = $this->flagRows();
        self::assertCount(1, $rows);
        self::assertSame(['decisions/other', '2', '2026-11-30 09:05:00.000000'], [
            $rows[0]['rule'],
            $rows[0]['version'],
            $rows[0]['failed_at'],
        ]);
        self::assertSame(
            [RuleFailed::class, \LogicException::class],
            array_column($this->decode($rows[0]['error'])['previous'], 'class')
        );
    }

    public function testASuccessfulEvaluationClearsTheFlagOfThatRegistrationOnly(): void
    {
        $this->rules->add(new TestRule('test_state'), true);
        $this->seedFlag(12, '00A0');
        $this->seedFlag(12, '00A1');

        self::assertTrue($this->periodic()->evaluate($this->key));

        self::assertSame(['00A1'], array_column($this->flagRows(), 'code'));
    }

    public function testAnInteractiveUseCaseClearsTheFlagInItsTransaction(): void
    {
        $rule = new TestRule('test_state');
        $rule->onDecide = static function ($input) use ($rule): void {
            if ($input->registration->code === '00B0') {
                $rule->throws = new \RuntimeException('later failure');
            }
        };
        $this->rules->add($rule, true);
        $this->seedFlag(12, '00A0');
        $this->seedFlag(12, '00A1');

        $this->exec($this->touching($this->key));
        self::assertSame(['00A1'], array_column($this->flagRows(), 'code'));

        $this->seedFlag(12, '00A0');
        try {
            $this->exec($this->touching($this->key, new RegistrationKey(12, '00B0')));
            self::fail('The use case should have failed.');
        } catch (SystemFailure) {
            self::assertSame(['00A0', '00A1'], array_column($this->flagRows(), 'code'));
        }
    }

    public function testAFlagThatCannotBeWrittenIsLoggedOnceWithoutAMessage(): void
    {
        $rule = new TestRule('test_state');
        $rule->throws = new \RuntimeException('secret rule message');
        $this->rules->add($rule, true);
        $this->breakFlagWrites();

        $result = null;
        $log = $this->capturedErrorLog(function () use (&$result): void {
            $result = $this->periodic()->evaluate($this->key);
        });

        self::assertFalse($result);
        self::assertSame([], $this->flagRows());
        self::assertCount(1, array_filter(explode("\n", trim($log))));
        self::assertStringContainsString(
            'Kaláka: evaluation failure not flagged: rule decisions/test_state, correlation '
            . Correlation::id() . ': ' . SystemFailure::class,
            $log
        );
        self::assertStringNotContainsString('secret rule message', $log);
        self::assertStringNotContainsString('Database error', $log);
        self::assertStringNotContainsString('00A0', $log);
    }
}
