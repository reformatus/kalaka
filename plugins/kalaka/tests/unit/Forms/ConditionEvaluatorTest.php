<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\Condition\Comparison;
use Kalaka\Forms\Engine\Condition\Condition;
use Kalaka\Forms\Engine\Condition\ConditionEvaluator;
use Kalaka\Forms\Engine\Condition\DateReference;
use Kalaka\Forms\Engine\Condition\MatchMode;
use Kalaka\Forms\Engine\Condition\Operator;
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
    public function testNoneIsUnknownForEveryOperatorButEmptyAndNotKeepsItUnknown(
        Operator $operator,
        string $given
    ): void {
        foreach (ValueType::cases() as $type) {
            $none = Value::none($type);
            self::assertTrue($this->holds($none, Operator::Empty, null), $type->value . ' empty');
            self::assertFalse($this->holds($none, Operator::Empty, null, true), $type->value . ' not empty');
            if ($operator->isAllowedFor($type)) {
                self::assertFalse($this->holds($none, $operator, $given), $type->value . ' ' . $operator->value);
                self::assertFalse(
                    $this->holds($none, $operator, $given, true),
                    $type->value . ' not ' . $operator->value
                );
            }
        }
    }

    /** @return iterable<string, array{MatchMode, list<?bool>, ?bool}> the items' results and the group's */
    public static function truthTable(): iterable
    {
        yield 'all: true, true' => [MatchMode::All, [true, true], true];
        yield 'all: true, false' => [MatchMode::All, [true, false], false];
        yield 'all: true, unknown' => [MatchMode::All, [true, null], null];
        yield 'all: false, unknown' => [MatchMode::All, [false, null], false];
        yield 'all: unknown, unknown' => [MatchMode::All, [null, null], null];
        yield 'any: false, false' => [MatchMode::Any, [false, false], false];
        yield 'any: false, true' => [MatchMode::Any, [false, true], true];
        yield 'any: false, unknown' => [MatchMode::Any, [false, null], null];
        yield 'any: true, unknown' => [MatchMode::Any, [true, null], true];
        yield 'any: unknown, unknown' => [MatchMode::Any, [null, null], null];
    }

    /**
     * @param list<?bool> $results
     * @dataProvider truthTable
     */
    public function testGroupsFollowTheThreeValuedTruthTableAndNotLeavesUnknownUnknown(
        MatchMode $match,
        array $results,
        ?bool $expected
    ): void {
        // true: age > 9; false: age > 99; unknown: `unset` has no value
        $item = static fn (?bool $result): Comparison => match ($result) {
            true => new Comparison('age', Operator::GreaterThan, '9', false),
            false => new Comparison('age', Operator::GreaterThan, '99', false),
            null => new Comparison('unset', Operator::Equals, 'x', false),
        };
        $items = array_map($item, $results);
        $context = new ValueContext(['age' => Value::number('12', '12'), 'unset' => Value::none(ValueType::Text)]);
        $evaluator = new ConditionEvaluator();
        $wrap = static fn (bool $not): Condition => new Condition(
            MatchMode::All,
            [new Condition($match, $items, $not)]
        );

        self::assertSame($expected === true, $evaluator->holds($wrap(false), $context));
        // `not` of a group: true -> false, false -> true, unknown -> unknown (never matched)
        self::assertSame($expected === false, $evaluator->holds($wrap(true), $context));
    }

    /** The lunch rule of the IVK fee: `{key: ebed, equals: nem, not: true}`. */
    public function testNotEqualsDoesNotMatchSomeoneWhoNeverAnswered(): void
    {
        $rule = new Condition(MatchMode::All, [new Comparison('ebed', Operator::Equals, 'nem', true)]);
        $holds = static fn (Value $answer): bool => (new ConditionEvaluator())->holds(
            $rule,
            new ValueContext(['ebed' => $answer])
        );

        self::assertFalse($holds(Value::none(ValueType::Choice)));
        self::assertTrue($holds(Value::choice(['igen'], 'Igen')));
        self::assertFalse($holds(Value::choice(['nem'], 'Nem')));

        $orEmpty = new Condition(MatchMode::Any, [
            new Comparison('ebed', Operator::Empty, null, false),
            new Comparison('ebed', Operator::Equals, 'nem', true),
        ]);
        self::assertTrue((new ConditionEvaluator())->holds(
            $orEmpty,
            new ValueContext(['ebed' => Value::none(ValueType::Choice)])
        ));
    }

    /** "16 or over": the date of birth is on or before the event's start minus 16 years. */
    public function testNotSixteenOrOverDoesNotMatchAMissingDateOfBirth(): void
    {
        $sixteen = new DateReference('event.start', -16);
        $rule = new Condition(MatchMode::All, [
            new Comparison('szuletesi_datum', Operator::LessOrEqual, null, true, $sixteen),
        ]);
        $holds = static fn (Value $born, Value $start): bool => (new ConditionEvaluator())->holds(
            $rule,
            new ValueContext(['szuletesi_datum' => $born, 'event.start' => $start])
        );
        $start = Value::date('2027-07-10', '2027-07-10');

        self::assertFalse($holds(Value::none(ValueType::Date), $start));
        self::assertTrue($holds(Value::date('2015-01-01', 'x'), $start));
        self::assertFalse($holds(Value::date('2005-01-01', 'x'), $start));
        // a missing referenced date is unknown too
        self::assertFalse($holds(Value::date('2005-01-01', 'x'), Value::none(ValueType::Date)));
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
