<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Foundation\Evaluation;

use Kalaka\Foundation\Decision\DecisionRules;
use WP_UnitTestCase;

require_once __DIR__ . '/TestRule.php';

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
        yield 'a malformed state' => [new TestRule('fine', ['on', 'Off'])];
        yield 'a state of 21 characters' => [new TestRule('fine', [str_repeat('s', 21)])];
        yield 'no states' => [new TestRule('fine', [])];
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

    public function testTheLongestAllowedKindAndStateAreAccepted(): void
    {
        $rules = new DecisionRules();

        $rules->add(new TestRule(str_repeat('k', 50), [str_repeat('s', 20), 'a_1']), true);

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
}
