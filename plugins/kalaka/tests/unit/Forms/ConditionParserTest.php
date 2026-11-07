<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Condition\Comparison;
use Kalaka\Forms\Condition\Condition;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\ContextScope;
use Kalaka\Forms\Condition\MatchMode;
use Kalaka\Forms\Condition\Operator;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** AC1: reading a condition in the stored format (D4, D5, D6). */
final class ConditionParserTest extends TestCase
{
    private ConditionParser $parser;
    private ContextScope $scope;

    protected function setUp(): void
    {
        $this->parser = new ConditionParser();
        $this->scope = new ContextScope(new ValueContext([
            'age' => Value::none(ValueType::Number),
            'born' => Value::none(ValueType::Date),
            'note' => Value::none(ValueType::Text),
            'fee' => Value::none(ValueType::Choice),
        ]));
    }

    /** @param array<string, mixed> $comparison */
    private function condition(array $comparison): array
    {
        return ['match' => 'all', 'items' => [$comparison]];
    }

    /** @return iterable<string, array{string, string, string}> type key, operator, value */
    public static function allowed(): iterable
    {
        foreach (['equals', 'lessThan', 'lessOrEqual', 'greaterThan', 'greaterOrEqual'] as $operator) {
            yield 'number ' . $operator => ['age', $operator, '10'];
            yield 'date ' . $operator => ['born', $operator, '2010-11-30'];
        }
        foreach (['equals', 'contains'] as $operator) {
            yield 'text ' . $operator => ['note', $operator, 'Anna'];
            yield 'choice ' . $operator => ['fee', $operator, 'basic'];
        }
    }

    /** @dataProvider allowed */
    public function testEveryOperatorOfATypeParsesWithAndWithoutNot(string $key, string $operator, string $value): void
    {
        foreach ([[], ['not' => false], ['not' => true]] as $not) {
            $condition = $this->parser->parse(
                $this->condition(['key' => $key, 'operator' => $operator, 'value' => $value] + $not),
                $this->scope
            );

            $comparison = $condition->items[0];
            self::assertInstanceOf(Comparison::class, $comparison);
            self::assertSame($key, $comparison->key);
            self::assertSame(Operator::from($operator), $comparison->operator);
            self::assertSame($value, $comparison->value);
            self::assertSame($not['not'] ?? false, $comparison->not);
        }
    }

    public function testEmptyTakesNoValueForEveryType(): void
    {
        foreach (['age', 'born', 'note', 'fee'] as $key) {
            $condition = $this->parser->parse(
                $this->condition(['key' => $key, 'operator' => 'empty', 'not' => true]),
                $this->scope
            );
            $comparison = $condition->items[0];
            self::assertInstanceOf(Comparison::class, $comparison);
            self::assertNull($comparison->value);
            self::assertTrue($comparison->not);
        }
    }

    public function testGroupsOfEachMatchParseOneLevelDeepBesideComparisons(): void
    {
        foreach (['all' => MatchMode::All, 'any' => MatchMode::Any] as $name => $mode) {
            $condition = $this->parser->parse([
                'match' => $name,
                'items' => [
                    ['key' => 'fee', 'operator' => 'equals', 'value' => 'basic'],
                    ['match' => $name, 'not' => true, 'items' => [
                        ['key' => 'age', 'operator' => 'greaterThan', 'value' => '9'],
                        ['key' => 'note', 'operator' => 'empty'],
                    ]],
                ],
            ], $this->scope);

            self::assertSame($mode, $condition->match);
            self::assertFalse($condition->not);
            self::assertInstanceOf(Comparison::class, $condition->items[0]);
            $group = $condition->items[1];
            self::assertInstanceOf(Condition::class, $group);
            self::assertSame($mode, $group->match);
            self::assertTrue($group->not);
            self::assertCount(2, $group->items);
        }
    }

    /** @return iterable<string, array{mixed, string}> the stored condition and what the failure names */
    public static function problems(): iterable
    {
        $compare = static fn (array $c): array => ['match' => 'all', 'items' => [$c]];

        yield 'unknown operator' => [$compare(['key' => 'age', 'operator' => 'near', 'value' => '1']), 'invalid_condition'];
        yield 'contains on a number' => [$compare(['key' => 'age', 'operator' => 'contains', 'value' => '1']), 'invalid_condition age'];
        yield 'ordering on a text' => [$compare(['key' => 'note', 'operator' => 'lessThan', 'value' => 'a']), 'invalid_condition note'];
        yield 'ordering on a choice' => [$compare(['key' => 'fee', 'operator' => 'greaterThan', 'value' => 'a']), 'invalid_condition fee'];
        yield 'value missing' => [$compare(['key' => 'age', 'operator' => 'equals']), 'invalid_condition age'];
        yield 'number value ill-formed' => [$compare(['key' => 'age', 'operator' => 'equals', 'value' => '1,5']), 'invalid_condition age'];
        yield 'number value not a string' => [$compare(['key' => 'age', 'operator' => 'equals', 'value' => 10]), 'invalid_condition age'];
        yield 'date value ill-formed' => [$compare(['key' => 'born', 'operator' => 'equals', 'value' => '2010-02-30']), 'invalid_condition born'];
        yield 'choice value empty' => [$compare(['key' => 'fee', 'operator' => 'equals', 'value' => '']), 'invalid_condition fee'];
        yield 'value with empty' => [$compare(['key' => 'note', 'operator' => 'empty', 'value' => 'x']), 'invalid_condition note'];
        yield 'group in a group' => [['match' => 'all', 'items' => [
            ['match' => 'any', 'items' => [['match' => 'all', 'items' => [['key' => 'age', 'operator' => 'empty']]]]],
        ]], 'invalid_condition'];
        yield 'empty items' => [['match' => 'all', 'items' => []], 'invalid_condition'];
        yield 'empty items in a group' => [['match' => 'all', 'items' => [['match' => 'any', 'items' => []]]], 'invalid_condition'];
        yield 'item of neither shape' => [['match' => 'all', 'items' => [['key' => 'age']]], 'invalid_condition'];
        yield 'item of both shapes' => [['match' => 'all', 'items' => [
            ['key' => 'age', 'operator' => 'empty', 'items' => []],
        ]], 'invalid_condition'];
        yield 'item that is no object' => [['match' => 'all', 'items' => ['age']], 'invalid_condition'];
        yield 'not that is no boolean' => [$compare(['key' => 'age', 'operator' => 'empty', 'not' => 'yes']), 'invalid_condition'];
        yield 'not on a group that is no boolean' => [['match' => 'all', 'items' => [
            ['match' => 'all', 'not' => 1, 'items' => [['key' => 'age', 'operator' => 'empty']]],
        ]], 'invalid_condition'];
        yield 'not on the top level' => [['match' => 'all', 'not' => true, 'items' => [
            ['key' => 'age', 'operator' => 'empty'],
        ]], 'invalid_condition'];
        yield 'unknown match' => [['match' => 'none', 'items' => [['key' => 'age', 'operator' => 'empty']]], 'invalid_condition'];
        yield 'match missing' => [['items' => [['key' => 'age', 'operator' => 'empty']]], 'invalid_condition'];
        yield 'unknown property' => [$compare(['key' => 'age', 'operator' => 'empty', 'label' => 'x']), 'invalid_condition'];
        yield 'not an object' => ['age', 'invalid_condition'];
        yield 'nothing' => [null, 'invalid_condition'];
        yield 'a list' => [[['key' => 'age', 'operator' => 'empty']], 'invalid_condition'];
        yield 'key undeclared' => [$compare(['key' => 'other', 'operator' => 'empty']), 'unknown_value_key other'];
    }

    /** @dataProvider problems */
    public function testEachProblemIsADefinitionFailureNamingTheProblemAndTheKey(mixed $stored, string $expected): void
    {
        try {
            $this->parser->parse($stored, $this->scope);
            self::fail('The condition was accepted.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testAFailureNeverNamesAValue(): void
    {
        try {
            $this->parser->parse(
                $this->condition(['key' => 'age', 'operator' => 'equals', 'value' => 'Kovács Anna titkos']),
                $this->scope
            );
            self::fail('The condition was accepted.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('invalid_condition age', $e->getMessage());
            self::assertStringNotContainsString('titkos', $e->getMessage());
        }
    }
}
