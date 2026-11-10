<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Condition\Comparison;
use Kalaka\Forms\Condition\Condition;
use Kalaka\Forms\Condition\ConditionEvaluator;
use Kalaka\Forms\Condition\ConditionParser;
use Kalaka\Forms\Condition\ContextScope;
use Kalaka\Forms\Condition\DateReference;
use Kalaka\Forms\Condition\MatchMode;
use Kalaka\Forms\Condition\Operator;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** S76: a date compared with a date reference, e.g. the age at the event's start. */
final class DateReferenceTest extends TestCase
{
    /** Under 16 at the event's start: born after the day sixteen years before the start. */
    private const UNDER_16 = [
        'match' => 'all',
        'items' => [
            ['key' => 'born', 'operator' => 'greaterThan', 'value' => ['key' => 'event.start', 'years' => -16]],
        ],
    ];

    private function facts(?string $born, ?string $start = '2027-07-10'): ValueContext
    {
        return new ValueContext([
            'born' => $born === null ? Value::none(ValueType::Date) : Value::date($born, $born),
            'note' => Value::text('Teszt', 'Teszt'),
            'event.start' => $start === null ? Value::none(ValueType::Date) : Value::date($start, $start),
        ]);
    }

    private function under16(ValueContext $facts): bool
    {
        $condition = (new ConditionParser())->parse(self::UNDER_16, new ContextScope($facts));

        return (new ConditionEvaluator())->holds($condition, $facts);
    }

    public function testTheReferenceParsesWithItsMoves(): void
    {
        $condition = (new ConditionParser())->parse(self::UNDER_16, new ContextScope($this->facts(null)));

        self::assertEquals(
            new Condition(MatchMode::All, [
                new Comparison('born', Operator::GreaterThan, null, false, new DateReference('event.start', -16)),
            ]),
            $condition
        );
        self::assertSame(['born', 'event.start'], $condition->keys());
    }

    /** @return iterable<string, array{string, bool}> date of birth, under 16 at a start of 2027-07-10 */
    public static function births(): iterable
    {
        yield 'turns 16 the day before the start' => ['2011-07-09', false];
        yield 'turns 16 on the first day' => ['2011-07-10', false];
        yield 'turns 16 the day after the start' => ['2011-07-11', true];
        yield 'well under' => ['2015-01-01', true];
    }

    /** @dataProvider births */
    public function testTheAgeAtTheStartIsComparedByCalendarDay(string $born, bool $under16): void
    {
        self::assertSame($under16, $this->under16($this->facts($born)));
    }

    public function testAnEmptyDateOrAnEmptyReferenceNeverHolds(): void
    {
        self::assertFalse($this->under16($this->facts(null)));
        self::assertFalse($this->under16($this->facts('2015-01-01', null)));
    }

    public function testDaysMove(): void
    {
        self::assertSame('2027-07-03', (new DateReference('event.start', 0, -7))->from('2027-07-10'));
        self::assertSame('2027-03-01', (new DateReference('event.start', 0, 1))->from('2027-02-28'));
    }

    /**
     * N9: 29 February moved to a year without one is that February's last day, 28 February, not PHP's
     * overflow to 1 March. Years move first, then days; a leap day moved to a leap year stays.
     */
    public function testALeapDayMovedToAYearWithoutOneIsTheLastDayOfFebruary(): void
    {
        self::assertSame('2013-02-28', (new DateReference('event.start', -15))->from('2028-02-29'));
        self::assertSame('2012-02-29', (new DateReference('event.start', -16))->from('2028-02-29'));
        self::assertSame('2013-03-01', (new DateReference('event.start', -15, 1))->from('2028-02-29'));
        self::assertSame('2032-02-29', (new DateReference('event.start', 4))->from('2028-02-29'));
        self::assertSame('2013-01-31', (new DateReference('event.start', -15))->from('2028-01-31'));
    }

    /**
     * The age rule at an event starting on 29 February 2028, "under 15 at the start" written as
     * `born greaterThan {event.start, years: -15}`: someone born on 1 March 2013 turns 15 on
     * 1 March 2028, so on the start they are 14 and under 15; someone born on 28 February 2013 turned 15
     * the day before the start. With PHP's overflow (1 March) the first would wrongly count as not under 15.
     */
    public function testUnderFifteenAtALeapDayStart(): void
    {
        $under15 = [
            'match' => 'all',
            'items' => [
                ['key' => 'born', 'operator' => 'greaterThan', 'value' => ['key' => 'event.start', 'years' => -15]],
            ],
        ];
        $holds = function (string $born) use ($under15): bool {
            $facts = $this->facts($born, '2028-02-29');
            $condition = (new ConditionParser())->parse($under15, new ContextScope($facts));

            return (new ConditionEvaluator())->holds($condition, $facts);
        };

        self::assertTrue($holds('2013-03-01'), 'born 2013-03-01 is 14 on 2028-02-29');
        self::assertFalse($holds('2013-02-28'), 'born 2013-02-28 turned 15 on 2028-02-28');
        self::assertFalse($holds('2013-01-15'));
        self::assertTrue($holds('2014-01-01'));
    }

    /** @return iterable<string, array{mixed}> a reference that is not valid */
    public static function invalid(): iterable
    {
        yield 'not a date key' => [['key' => 'note']];
        yield 'undeclared key' => [['key' => 'event.nope']];
        yield 'no key' => [['years' => -16]];
        yield 'years as a string' => [['key' => 'event.start', 'years' => '-16']];
        yield 'an unknown member' => [['key' => 'event.start', 'months' => 2]];
    }

    /** @dataProvider invalid */
    public function testAnInvalidReferenceIsADefinitionProblem(mixed $reference): void
    {
        $this->expectException(SystemFailure::class);

        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'born', 'operator' => 'lessThan', 'value' => $reference]]],
            new ContextScope($this->facts(null))
        );
    }

    public function testOnlyADateComparesWithAReference(): void
    {
        $this->expectException(SystemFailure::class);

        (new ConditionParser())->parse(
            [
                'match' => 'all',
                'items' => [['key' => 'note', 'operator' => 'equals', 'value' => ['key' => 'event.start']]],
            ],
            new ContextScope($this->facts(null))
        );
    }
}
