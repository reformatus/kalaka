<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\Foundation\Decision\DecisionRules;
use Kalaka\Foundation\Evaluation\RuleFailed;
use Kalaka\Registrations\Api\RegistrationKey;
use Kalaka\Tests\Integration\Foundation\AdminProbe;
use Kalaka\Tests\Integration\Foundation\NamedProbe;
use Kalaka\Tests\Integration\Foundation\TestItem;

/** The decisions phase of the runner's evaluation: foundation.md, "Evaluation". */
final class EvaluationDecisionsTest extends EvaluationTestCase
{
    public function testARuleIsAskedOnceAfterTheOuterExecuteAndItsTransitionIsStored(): void
    {
        $rule = new TestRule('test_state');
        $rule->answer = 'on';
        $this->rules->add($rule, true);
        $askedDuringExecute = null;
        $nested = new NamedProbe(function (array $input, Context $context): void {
            $this->items->upsert(new TestItem('third', regEvent: 12, regCode: '00A0'), $context);
        });
        $this->seedRegistration(12, '00A0');
        $outer = new AdminProbe(function (
            array $input,
            Context $context
        ) use (
            $rule,
            $nested,
            &$askedDuringExecute
        ): void {
            $this->lockRegistrations($context, new RegistrationKey(12, '00A0'));
            $this->items->upsert(new TestItem('first', regEvent: 12, regCode: '00A0'), $context);
            $this->items->upsert(new TestItem('second', regEvent: 12, regCode: '00A0'), $context);
            $context->run($nested, []);
            $askedDuringExecute = count($rule->inputs);
        });

        $this->exec($outer);

        self::assertSame(0, $askedDuringExecute);
        self::assertCount(1, $rule->inputs);
        $input = $rule->inputs[0];
        self::assertEquals(new RegistrationKey(12, '00A0'), $input->registration);
        self::assertSame(
            '2026-11-30 09:00:00',
            $input->now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
        self::assertSame([], $input->decisions);

        $rows = $this->decisionRows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(
            ['12', '00A0', 'test_state', 'on'],
            [$row['event_id'], $row['code'], $row['kind'], $row['state']]
        );
        self::assertSame('2026-11-29 22:59:59.000000', $row['effective_at']);
        self::assertSame('2026-11-30 09:00:00.000000', $row['recorded_at']);
        self::assertSame(['test_record', '12', '7'], [$row['cause_type'], $row['cause_event_id'], $row['cause_key']]);
        self::assertSame('user', $row['actor_type']);
        self::assertSame((string) $this->admin->userId, $row['actor_user_id']);
        self::assertNull($row['actor_event_id']);
        self::assertNull($row['actor_code']);

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertContains('registration/12/00A0', $this->subjectsOf($entries[0]['id'] ?? 0));
        self::assertNotContains('registration/12/00A0', $this->subjectsOf($entries[1]['id'] ?? 0));
        self::assertNull($entries[0]['parent_id']);
        self::assertSame($entries[0]['id'], $entries[1]['parent_id']);
        self::assertStringNotContainsString('test_state', (string) $entries[1]['changes']);
    }

    public function testADecisionIsNeverRecomputedOnlyAChangedStateIsWritten(): void
    {
        $rule = new TestRule('test_state');
        $this->rules->add($rule, true);
        $key = new RegistrationKey(12, '00A0');
        $this->seedRegistration(12, '00A0');

        $rule->answer = 'on';
        $this->exec($this->touching($key));
        self::assertCount(1, $this->decisionRows());

        $rule->answer = null;
        $this->exec($this->touching($key));
        self::assertCount(1, $this->decisionRows());
        self::assertSame('on', $this->decisionRows()[0]['state']);

        $rule->answer = 'on';
        $this->exec($this->touching($key));
        self::assertCount(1, $this->decisionRows());

        $rule->answer = 'off';
        $this->exec($this->touching($key));
        self::assertSame(['on', 'off'], array_column($this->decisionRows(), 'state'));

        $rule->answer = null;
        $rule->inputs = [];
        $this->exec($this->touching($key));
        self::assertSame('off', $rule->inputs[0]->decisions['test_state']->state);
    }

    public function testARuleReadsItsOwnKindAndTheKindsDecidedBeforeIt(): void
    {
        $asked = new \ArrayObject();
        $rules = [];
        foreach (['a', 'b', 'x', 'y'] as $kind) {
            $rules[$kind] = new TestRule($kind, ['p', 'q'], $asked);
            $rules[$kind]->answer = 'q';
        }
        $this->rules->add($rules['a'], true);
        $this->rules->add($rules['b'], true);
        $this->rules->add($rules['x'], false);
        $this->rules->add($rules['y'], false);
        foreach (['b', 'a', 'y', 'x'] as $kind) {
            $this->seedDecision(12, '00A0', $kind, 'p');
        }
        $this->seedRegistration(12, '00A0');

        $this->exec($this->touching(new RegistrationKey(12, '00A0')));

        self::assertSame(['a', 'b', 'x', 'y'], $asked->getArrayCopy());
        $seen = static function (string $kind) use ($rules): array {
            $states = array_map(static fn ($decision): string => $decision->state, $rules[$kind]->inputs[0]->decisions);
            ksort($states);

            return $states;
        };
        self::assertSame(['a' => 'p'], $seen('a'));
        self::assertSame(['a' => 'q', 'b' => 'p'], $seen('b'));
        self::assertSame(['a' => 'q', 'b' => 'q', 'x' => 'p'], $seen('x'));
        self::assertSame(['a' => 'q', 'b' => 'q', 'y' => 'p'], $seen('y'));
    }

    /** @return iterable<string, array{string}> */
    public static function failingRules(): iterable
    {
        yield 'a state outside its states' => ['state'];
        yield 'an exception' => ['throws'];
    }

    /** @dataProvider failingRules */
    public function testARuleFailureRollsBackTheWholeUseCaseAndFailsTheAudit(string $how): void
    {
        $earlier = new TestRule('earlier', ['on', 'off']);
        $earlier->answer = 'on';
        $rule = new TestRule('test_state');
        $rule->onDecide = static function ($input) use ($rule, $how): void {
            if ($input->registration->code === '00B0') {
                if ($how === 'state') {
                    $rule->answer = 'sideways';
                } else {
                    $rule->throws = new \RuntimeException('secret rule message');
                }
            }
        };
        $this->rules->add($earlier, true);
        $this->rules->add($rule, true);
        $this->seedFlag(12, '00A0');
        $this->seedRegistration(12, '00A0', '00B0');
        $flagsBefore = $this->flagRows();

        $caught = null;
        try {
            $this->exec($this->touching(new RegistrationKey(12, '00A0'), new RegistrationKey(12, '00B0')));
        } catch (SystemFailure $e) {
            $caught = $e;
        }

        self::assertInstanceOf(SystemFailure::class, $caught);
        self::assertSame(SystemFailure::class, $caught::class);
        self::assertSame(0, $this->countRows('kalaka_test_items'));
        self::assertSame([], $this->decisionRows());
        self::assertSame($flagsBefore, $this->flagRows());

        $entry = $this->onlyEntry();
        self::assertSame('failed', $entry['outcome']);
        $error = $this->decode($entry['error']);
        self::assertSame('kalaka/probe', $error['use_case']);
        self::assertSame(SystemFailure::class, $error['class']);
        $classes = array_column($error['previous'], 'class');
        self::assertSame(
            $how === 'state' ? [RuleFailed::class] : [RuleFailed::class, \RuntimeException::class],
            $classes
        );
        self::assertStringNotContainsString('secret rule message', (string) $entry['error']);
        self::assertStringNotContainsString('decisions/', (string) $entry['error']);
    }

    public function testAUseCaseThatTouchesNoRegistrationRunsNoStatementOnTheEvaluationTables(): void
    {
        global $wpdb;
        $this->rules->add(new TestRule('test_state'), true);
        $useCase = static fn (self $test): AdminProbe => new AdminProbe(
            function (array $input, Context $context) use ($test): void {
                $test->items->upsert(new TestItem('unmapped'), $context);
            }
        );

        $before = $wpdb->num_queries;
        $statements = $this->statementsDuring(fn () => $this->exec($useCase($this)));
        $withRules = $wpdb->num_queries - $before;

        $this->runner = new \Kalaka\Foundation\Api\UseCase\Runner($this->clockAt('2026-11-30 10:00:00'), true);
        $before = $wpdb->num_queries;
        $this->exec($useCase($this));
        $withoutRules = $wpdb->num_queries - $before;

        self::assertSame($withoutRules, $withRules);
        self::assertSame([], array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'kalaka_decisions')
                || str_contains($sql, 'kalaka_evaluation_failures')
        )));
    }

    public function testEachTouchedRegistrationIsEvaluatedOnceInAscendingOrder(): void
    {
        $rule = new TestRule('test_state');
        $this->rules->add($rule, true);
        $this->seedRegistration(12, '00A0', '00B1');
        $this->seedRegistration(3, '00Z9');

        $this->exec($this->touching(
            new RegistrationKey(12, '00B1'),
            new RegistrationKey(3, '00Z9'),
            new RegistrationKey(12, '00A0'),
            new RegistrationKey(12, '00B1'),
        ));

        self::assertSame(
            ['3/00Z9', '12/00A0', '12/00B1'],
            array_map(
                static fn ($input): string => $input->registration->eventId . '/' . $input->registration->code,
                $rule->inputs
            )
        );
    }

    public function testARetriedAttemptEvaluatesOnlyTheRegistrationsOfTheLastAttempt(): void
    {
        $rule = new TestRule('test_state');
        $this->rules->add($rule, true);
        $this->seedRegistration(12, '00A2', '00A3');
        $useCase = new AdminProbe(function (array $input, Context $context, int $call): void {
            $code = $call === 1 ? '00A2' : '00A3';
            $this->lockRegistrations($context, new RegistrationKey(12, $code));
            $this->items->upsert(new TestItem('attempt', regEvent: 12, regCode: $code), $context);
            if ($call === 1) {
                throw new \Kalaka\Foundation\Api\Error\RetryableFailure();
            }
        });

        $this->exec($useCase);

        self::assertSame(2, $useCase->calls);
        self::assertSame(['00A3'], array_map(static fn ($input): string => $input->registration->code, $rule->inputs));
    }

    public function testARegistrationFirstTouchedWhileEvaluatingIsEvaluatedToo(): void
    {
        $touched = [new RegistrationKey(12, '00A0')];
        $rule = new TestRule('test_state');
        $rule->onDecide = static function ($input) use (&$touched): void {
            if ($input->registration->code === '00A0') {
                $touched[] = new RegistrationKey(3, '00Z9');
                $touched[] = new RegistrationKey(12, '00A0');
            }
        };
        $evaluation = new \Kalaka\Foundation\Evaluation\Evaluation($this->rulesWith($rule));
        $context = new Context(
            $this->admin,
            $this->clockAt('2026-11-30 10:00:00')->now(),
            static fn () => null,
            static function (): void {
            },
            static function (): void {
            },
            static function (): void {
            },
            static function (): void {
            },
            static function (): void {
            },
            static function (): void {
            },
            static function () use (&$touched): array {
                return $touched;
            },
            static function (): void {
            },
            static function (): void {
            },
            static fn (): bool => true,
        );

        $evaluation->run($context);

        self::assertSame(
            ['12/00A0', '3/00Z9'],
            array_map(
                static fn ($input): string => $input->registration->eventId . '/' . $input->registration->code,
                $rule->inputs
            )
        );
    }

    public function testAKeyWithoutARowGetsNoDecisionAndLosesItsFlagWhileAKeyWithARowIsEvaluated(): void
    {
        $rule = new TestRule('test_state');
        $rule->answer = 'on';
        $this->rules->add($rule, true);
        $this->seedRegistration(12, '00A1');
        $this->seedFlag(12, '00A0');
        $this->seedFlag(12, '00A1');

        // Marked without a write: a write mapped to a key without a row would need a lock the run cannot hold.
        $this->exec(new AdminProbe(static function (array $input, Context $context): void {
            $context->markTouched(new RegistrationKey(12, '00A0'));
            $context->markTouched(new RegistrationKey(12, '00A1'));
        }));

        self::assertSame(['00A1'], array_map(static fn ($input): string => $input->registration->code, $rule->inputs));
        self::assertSame(
            [['12', '00A1', 'test_state', 'on']],
            array_map(
                static fn (array $row): array => [$row['event_id'], $row['code'], $row['kind'], $row['state']],
                $this->decisionRows()
            )
        );
        self::assertSame([], $this->flagRows());
    }

    public function testTheEvaluationLocksATouchedRegistrationTheRunDoesNotHold(): void
    {
        $rule = new TestRule('test_state');
        $rule->answer = 'on';
        $this->rules->add($rule, true);
        $this->seedRegistration(12, '00A0');
        $key = new RegistrationKey(12, '00A0');

        $statements = $this->statementsDuring(fn () => $this->exec(new AdminProbe(
            static function (array $input, Context $context) use ($key): void {
                $context->markTouched($key);
            }
        )));

        self::assertCount(1, self::registrationLocks($statements));
        self::assertCount(1, $rule->inputs);
        self::assertCount(1, $this->decisionRows());
    }

    public function testARegistrationTheRunHoldsIsNotLockedAgain(): void
    {
        $this->rules->add(new TestRule('test_state'), true);
        $this->seedRegistration(12, '00A0');

        $statements = $this->statementsDuring(fn () => $this->exec($this->touching(new RegistrationKey(12, '00A0'))));

        self::assertCount(1, self::registrationLocks($statements));
    }

    public function testATouchedRegistrationTheEvaluationWouldLockAfterAHigherLockFailsTheUseCase(): void
    {
        $rule = new TestRule('test_state');
        $rule->answer = 'on';
        $this->rules->add($rule, true);
        $this->seedRegistration(12, '00A0');

        try {
            $this->exec(new AdminProbe(function (array $input, Context $context): void {
                $this->items->upsert(new TestItem('unlocked', regEvent: 12, regCode: '00A0'), $context);
            }));
            self::fail('The use case should have failed.');
        } catch (SystemFailure $caught) {
            self::assertStringContainsString('Lock order violated', (string) $caught->getPrevious()?->getMessage());
        }

        self::assertSame([], $rule->inputs);
        self::assertSame(0, $this->countRows('kalaka_test_items'));
        self::assertSame([], $this->decisionRows());
    }

    /**
     * @param list<string> $statements
     * @return list<string> the `FOR UPDATE` reads of a registration row among them
     */
    private static function registrationLocks(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'kalaka_registrations')
                && str_ends_with($sql, 'FOR UPDATE')
        ));
    }

    private function rulesWith(TestRule $rule): DecisionRules
    {
        $rules = new DecisionRules();
        $rules->add($rule, true);

        return $rules;
    }
}
