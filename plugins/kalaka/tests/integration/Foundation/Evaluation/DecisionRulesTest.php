<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Decision\DecisionRules;
use WP_UnitTestCase;

/** The registry of decision rules: what it refuses and the order it keeps. */
final class DecisionRulesTest extends WP_UnitTestCase
{
    /** @return iterable<string, array{TestRule}> */
    public static function invalidRules(): iterable
    {
        yield 'a kind registered twice' => [new TestRule('taken')];
        yield 'an upper-case kind' => [new TestRule('Kind')];
        yield 'a kind starting with a digit' => [new TestRule('1kind')];
        yield 'a kind with a dash' => [new TestRule('my-kind')];
        yield 'a kind of 51 characters' => [new TestRule(str_repeat('k', 51))];
        yield 'a malformed state' => [new TestRule('fine', ['on' => 'Be', 'Off' => 'Ki'])];
        yield 'a state of 21 characters' => [new TestRule('fine', [str_repeat('s', 21) => 'Teszt'])];
        yield 'no states' => [new TestRule('fine', [])];
        yield 'an empty kind label' => [new TestRule('fine', label: '')];
        yield 'a blank kind label' => [new TestRule('fine', label: '  ')];
        yield 'an empty state label' => [new TestRule('fine', ['on' => 'Be', 'off' => ''])];
    }

    /** @dataProvider invalidRules */
    public function testARuleThatBreaksTheFormIsRefusedAndNotRegistered(TestRule $rule): void
    {
        $rules = new DecisionRules();
        $rules->add(new TestRule('taken'), true);

        try {
            $rules->add($rule, false);
            self::fail('The rule should have been refused.');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $rules->core());
            self::assertSame([], $rules->extension());
        }
    }

    /** @dataProvider invalidMarks */
    public function testARuleWhoseMarksBreakTheFormIsRefusedAndNotRegistered(array $marks): void
    {
        $rule = new TestRule('fine', ['on' => 'Be', 'off' => 'Ki']);
        $rule->marks = $marks;
        $rules = new DecisionRules();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $rules->add($rule, false);
        } finally {
            self::assertSame([], $rules->extension());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidMarks(): iterable
    {
        yield 'a mark of an undeclared state' => [['maybe' => ['icon' => 'yes-alt', 'tone' => 'ok']]];
        yield 'a mark with an empty icon' => [['on' => ['icon' => ' ', 'tone' => 'ok']]];
    }

    public function testMarksOnDeclaredStatesAreAccepted(): void
    {
        $rule = new TestRule('fine', ['on' => 'Be', 'off' => 'Ki']);
        $rule->marks = ['on' => ['icon' => 'yes-alt', 'tone' => 'ok'], 'off' => ['icon' => 'marker', 'tone' => '']];
        $rules = new DecisionRules();

        $rules->add($rule, false);

        self::assertSame([$rule], $rules->extension());
    }

    public function testTheLongestAllowedKindAndStateAreAccepted(): void
    {
        $rules = new DecisionRules();

        $rules->add(new TestRule(str_repeat('k', 50), [str_repeat('s', 20) => 'Hosszú', 'a_1' => 'Teszt']), true);

        self::assertCount(1, $rules->core());
    }

    public function testCoreRulesComeBeforeExtensionRulesEachInTheOrderGiven(): void
    {
        $rules = new DecisionRules();
        $first = new TestRule('first');
        $second = new TestRule('second');
        $third = new TestRule('third');
        $fourth = new TestRule('fourth');

        $rules->add($third, false);
        $rules->add($first, true);
        $rules->add($fourth, false);
        $rules->add($second, true);

        self::assertSame([$first, $second], $rules->core());
        self::assertSame([$third, $fourth], $rules->extension());
    }

    public function testAllKeepsReadingOrderAndTheDeclaredStatesAndLabels(): void
    {
        $rules = new DecisionRules();
        $extension = new TestRule('ext', ['x' => 'Iksz'], label: 'Bővítmény');
        $core = new TestRule('core', ['b' => 'Bé', 'a' => 'Á'], label: 'Mag');

        $rules->add($extension, false);
        $rules->add($core, true);

        self::assertSame([$core, $extension], $rules->all());
        self::assertSame(['b' => 'Bé', 'a' => 'Á'], $rules->all()[0]->states());
    }

    public function testTheBootPublishesTheRegistryTheEvaluationUsesWithCoresConfirmation(): void
    {
        self::assertSame(DecisionRules::current(), DecisionRules::current());
        self::assertSame(
            ['confirmation'],
            array_map(static fn ($rule): string => $rule->kind(), DecisionRules::current()->all())
        );
        self::assertSame(['confirmation'], array_map(
            static fn ($rule): string => $rule->kind(),
            DecisionRules::current()->core()
        ));
    }

    public function testTheRulesAreDeclaredCoreFirstThenExtensionsEachAsAdded(): void
    {
        $rules = new DecisionRules();
        $extFirst = new TestRule('ext_first');
        $coreFirst = new TestRule('core_first');
        $extSecond = new TestRule('ext_second');
        $coreSecond = new TestRule('core_second');

        $rules->add($extFirst, false);
        $rules->add($coreFirst, true);
        $rules->add($extSecond, false);
        $rules->add($coreSecond, true);

        self::assertSame([$coreFirst, $coreSecond, $extFirst, $extSecond], $rules->all());
    }
}
