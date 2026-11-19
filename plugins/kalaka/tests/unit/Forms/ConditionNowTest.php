<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\Condition\Comparison;
use Kalaka\Forms\Engine\Condition\Condition;
use Kalaka\Forms\Engine\Condition\ConditionEvaluator;
use Kalaka\Forms\Engine\Condition\ConditionParser;
use Kalaka\Forms\Engine\Condition\ContextScope;
use Kalaka\Forms\Engine\Condition\DateReference;
use Kalaka\Forms\Engine\Condition\MatchMode;
use Kalaka\Forms\Engine\Condition\Operator;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** The date reference "now": the fact source's day, moved like any date reference. */
final class ConditionNowTest extends TestCase
{
    private const OPEN = [
        'match' => 'all',
        'items' => [
            ['key' => 'closes', 'operator' => 'greaterOrEqual', 'value' => ['ref' => 'now', 'years' => 0, 'days' => 0]],
        ],
    ];

    private function facts(string $now, ?string $closes = '2026-12-15'): ValueContext
    {
        return new ValueContext([
            'closes' => $closes === null ? Value::none(ValueType::Date) : Value::date($closes, $closes),
            Facts::NOW => Value::date($now, $now),
        ]);
    }

    private function holds(mixed $condition, ValueContext $facts): bool
    {
        return (new ConditionEvaluator())->holds(
            (new ConditionParser())->parse($condition, new ContextScope($facts)),
            $facts
        );
    }

    public function testTheReferenceParsesToTheNowKeyWithItsMoves(): void
    {
        $condition = (new ConditionParser())->parse(self::OPEN, new ContextScope($this->facts('2026-11-30')));

        self::assertEquals(
            new Condition(MatchMode::All, [
                new Comparison('closes', Operator::GreaterOrEqual, null, false, DateReference::now()),
            ]),
            $condition
        );
        self::assertTrue(DateReference::now()->isNow());
        self::assertFalse((new DateReference('event.start'))->isNow());
        self::assertSame(['closes', Facts::NOW], $condition->keys());
    }

    public function testMovesAreOptional(): void
    {
        $condition = (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'closes', 'operator' => 'lessThan', 'value' => ['ref' => 'now']]]],
            new ContextScope($this->facts('2026-11-30'))
        );

        self::assertEquals(DateReference::now(), $condition->items[0]->reference);
    }

    public function testADateBeforeAndAfterNowAtTheFactSourcesClock(): void
    {
        self::assertTrue($this->holds(self::OPEN, $this->facts('2026-12-14')));
        self::assertTrue($this->holds(self::OPEN, $this->facts('2026-12-15')), 'the day itself');
        self::assertFalse($this->holds(self::OPEN, $this->facts('2026-12-16')));
    }

    public function testNowMovesByYearsAndDays(): void
    {
        $under16 = [
            'match' => 'all',
            'items' => [
                [
                    'key' => 'closes',
                    'operator' => 'greaterThan',
                    'value' => ['ref' => 'now', 'years' => -16, 'days' => 2],
                ],
            ],
        ];

        // now 2026-11-30 moved: 2010-12-02.
        self::assertTrue($this->holds($under16, $this->facts('2026-11-30', '2010-12-03')));
        self::assertFalse($this->holds($under16, $this->facts('2026-11-30', '2010-12-02')));
    }

    public function testAnEmptyDateNeverHoldsAgainstNow(): void
    {
        self::assertFalse($this->holds(self::OPEN, $this->facts('2026-11-30', null)));
    }

    public function testAFactSourceWithoutTheNowKeyFailsNeverGuesses(): void
    {
        $facts = new ValueContext(['closes' => Value::date('2026-12-15', '')]);
        $condition = (new ConditionParser())->parse(self::OPEN, new ContextScope($this->facts('2026-11-30')));

        $this->expectException(SystemFailure::class);
        (new ConditionEvaluator())->holds($condition, $facts);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'another reference name' => [['ref' => 'today']];
        yield 'ref as a non-string' => [['ref' => 1]];
        yield 'ref with a key' => [['ref' => 'now', 'key' => 'closes']];
        yield 'an unknown member' => [['ref' => 'now', 'months' => 1]];
        yield 'years as a string' => [['ref' => 'now', 'years' => '1']];
        yield 'years past the bound' => [['ref' => 'now', 'years' => 201]];
        yield 'days past the bound' => [['ref' => 'now', 'days' => -3661]];
        yield 'the now key named as a key' => [['key' => Facts::NOW]];
    }

    /** @dataProvider invalid */
    public function testAMalformedReferenceIsADefinitionProblem(mixed $reference): void
    {
        $this->expectException(SystemFailure::class);

        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'closes', 'operator' => 'lessThan', 'value' => $reference]]],
            new ContextScope($this->facts('2026-11-30'))
        );
    }

    public function testOnlyADateComparesWithNow(): void
    {
        $facts = new ValueContext([
            'note' => Value::text('Teszt', 'Teszt'),
            Facts::NOW => Value::date('2026-11-30', ''),
        ]);

        $this->expectException(SystemFailure::class);
        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'note', 'operator' => 'equals', 'value' => ['ref' => 'now']]]],
            new ContextScope($facts)
        );
    }
}
