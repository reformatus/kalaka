<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Condition\Comparison;
use Kalaka\Forms\Condition\Condition;
use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\Condition\MatchMode;
use Kalaka\Forms\Condition\Operator;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** AC2: evaluation, one row per type and operator, each also with `not` (D2, D5). */
final class ConditionEvaluatorTest extends TestCase
{
    private function holds(Value $value, Operator $operator, ?string $given, bool $not = false): bool
    {
        return (new ConditionEvaluator())->holds(
            new Condition(MatchMode::All, [new Comparison('k', $operator, $given, $not)]),
            new ValueContext(['k' => $value])
        );
    }

    /** @return iterable<string, array{Value, Operator, ?string, bool}> the value, the comparison and whether it holds */
    public static function rows(): iterable
    {
        $number = static fn (string $n): Value => Value::number($n, $n);
        $text = static fn (string $t): Value => Value::text($t, $t);

        yield 'number greater' => [$number('10'), Operator::GreaterThan, '9', true];
        yield 'number not greater' => [$number('9'), Operator::GreaterThan, '9', false];
        yield 'number less' => [$number('-1.5'), Operator::LessThan, '0', true];
        yield 'number not less' => [$number('0'), Operator::LessThan, '0', false];
        yield 'number equal as decimals' => [$number('2.50'), Operator::Equals, '2.5', true];
        yield 'number unequal' => [$number('2.51'), Operator::Equals, '2.5', false];
        yield 'number less or equal' => [$number('2.5'), Operator::LessOrEqual, '2.50', true];
        yield 'number greater or equal' => [$number('2.49'), Operator::GreaterOrEqual, '2.5', false];
        yield 'number exact beyond a float' => [
            $number('0.1000000000000000000001'),
            Operator::GreaterThan,
            '0.1',
            true,
        ];
        yield 'date less or equal on the day' => [
            Value::date('2010-11-30', 'x'),
            Operator::LessOrEqual,
            '2010-11-30',
            true,
        ];
        yield 'date less' => [Value::date('2010-11-30', 'x'), Operator::LessThan, '2010-11-30', false];
        yield 'date greater' => [Value::date('2011-01-01', 'x'), Operator::GreaterThan, '2010-12-31', true];
        yield 'date equal' => [Value::date('2011-01-01', 'x'), Operator::Equals, '2011-01-01', true];
        yield 'date greater or equal' => [
            Value::date('2010-12-31', 'x'),
            Operator::GreaterOrEqual,
            '2011-01-01',
            false,
        ];
        yield 'text equal, case-insensitive' => [$text('Anna'), Operator::Equals, 'anna', true];
        yield 'text unequal' => [$text('Anna'), Operator::Equals, 'Anna B', false];
        yield 'text contains a substring' => [$text('Kovács Anna'), Operator::Contains, 'ács', true];
        yield 'text contains, case-insensitive with accents' => [$text('Kovács Anna'), Operator::Contains, 'ÁCS', true];
        yield 'text does not contain' => [$text('Kovács Anna'), Operator::Contains, 'xyz', false];
        yield 'text accent-sensitive' => [$text('Á'), Operator::Equals, 'A', false];
        yield 'text case-folded' => [$text('Straße'), Operator::Equals, 'STRASSE', true];
        yield 'choice contains one of several' => [Value::choice(['a', 'b'], 'x'), Operator::Contains, 'b', true];
        yield 'choice contains none of them' => [Value::choice(['a', 'b'], 'x'), Operator::Contains, 'c', false];
        yield 'choice equals the only one' => [Value::choice(['a'], 'x'), Operator::Equals, 'a', true];
        yield 'choice of two does not equal one' => [Value::choice(['a', 'b'], 'x'), Operator::Equals, 'a', false];
        yield 'number empty' => [$number('0'), Operator::Empty, null, false];
        yield 'text empty' => [$text(''), Operator::Empty, null, true];
        yield 'choice empty' => [Value::choice([], ''), Operator::Empty, null, true];
        yield 'choice not empty' => [Value::choice(['a'], 'x'), Operator::Empty, null, false];
    }

    /** @dataProvider rows */
    public function testEachRowHoldsOrNotAndNotInvertsIt(
        Value $value,
        Operator $operator,
        ?string $given,
        bool $expected
    ): void {
        self::assertSame($expected, $this->holds($value, $operator, $given));
        self::assertSame(!$expected, $this->holds($value, $operator, $given, true));
    }

    /** @return iterable<string, array{Operator, ?string}> */
    public static function operatorsOfEachType(): iterable
    {
        yield 'equals' => [Operator::Equals, '1'];
        yield 'lessThan' => [Operator::LessThan, '1'];
        yield 'lessOrEqual' => [Operator::LessOrEqual, '1'];
        yield 'greaterThan' => [Operator::GreaterThan, '1'];
        yield 'greaterOrEqual' => [Operator::GreaterOrEqual, '1'];
        yield 'contains' => [Operator::Contains, 'a'];
    }

    /** @dataProvider operatorsOfEachType */
    public function testNoneIsEmptyAndFailsEveryOtherOperatorButNotInvertsThem(Operator $operator, string $given): void
    {
        foreach (ValueType::cases() as $type) {
            $none = Value::none($type);
            self::assertTrue($this->holds($none, Operator::Empty, null), $type->value . ' empty');
            self::assertFalse($this->holds($none, Operator::Empty, null, true), $type->value . ' not empty');
            if ($operator->isAllowedFor($type)) {
                self::assertFalse($this->holds($none, $operator, $given), $type->value . ' ' . $operator->value);
                self::assertTrue(
                    $this->holds($none, $operator, $given, true),
                    $type->value . ' not ' . $operator->value
                );
            }
        }
    }

    private function context(): ValueContext
    {
        return new ValueContext([
            'age' => Value::number('12', '12'),
            'fee' => Value::choice(['basic'], 'Alap'),
            'note' => Value::text('hello', 'hello'),
        ]);
    }

    /** @param list<Comparison|Condition> $items */
    private function holdsAll(MatchMode $match, array $items, bool $not = false): bool
    {
        return (new ConditionEvaluator())->holds(new Condition($match, $items, $not), $this->context());
    }

    public function testAllAndAnyOverTwoComparisons(): void
    {
        $yes = new Comparison('age', Operator::GreaterThan, '9', false);
        $no = new Comparison('fee', Operator::Equals, 'extra', false);

        self::assertFalse($this->holdsAll(MatchMode::All, [$yes, $no]));
        self::assertTrue($this->holdsAll(MatchMode::Any, [$yes, $no]));
        self::assertTrue($this->holdsAll(MatchMode::All, [$yes, $yes]));
        self::assertFalse($this->holdsAll(MatchMode::Any, [$no, $no]));
    }

    public function testAGroupWithNotAndAGroupBesideAComparison(): void
    {
        $yes = new Comparison('age', Operator::GreaterThan, '9', false);
        $no = new Comparison('fee', Operator::Equals, 'extra', false);

        $negated = new Condition(MatchMode::Any, [$no, $no], true);
        self::assertTrue($this->holdsAll(MatchMode::All, [$negated]));
        self::assertTrue($this->holdsAll(MatchMode::All, [$yes, $negated]));
        self::assertFalse($this->holdsAll(MatchMode::All, [$no, $negated]));

        $group = new Condition(MatchMode::All, [$yes, new Comparison('note', Operator::Contains, 'ell', false)]);
        self::assertTrue($this->holdsAll(MatchMode::Any, [$no, $group]));
        self::assertFalse($this->holdsAll(MatchMode::All, [$no, $group]));
    }

    public function testAKeyTheContextDoesNotDeclareIsAFailureNeverAnEmptyValue(): void
    {
        $this->expectException(SystemFailure::class);
        $this->holdsAll(MatchMode::All, [new Comparison('other', Operator::Empty, null, false)]);
    }

    public function testAnOperatorThatDoesNotFitTheValuesTypeIsAFailure(): void
    {
        $this->expectException(SystemFailure::class);
        $this->holdsAll(MatchMode::All, [new Comparison('age', Operator::Contains, '1', false)]);
    }
}
