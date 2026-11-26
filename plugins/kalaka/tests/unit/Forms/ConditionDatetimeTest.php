<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Facts;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\Condition\ConditionEvaluator;
use Kalaka\Forms\Engine\Condition\ConditionParser;
use Kalaka\Forms\Engine\Condition\ContextScope;
use Kalaka\Forms\Engine\Condition\DateReference;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/**
 * The datetime value and `now` as one: a day against anything compares on the day (a datetime gives the day it was
 * written on, never midnight), two datetimes as instants; a day-only condition means what it did.
 */
final class ConditionDatetimeTest extends TestCase
{
    private const ZONE = 'Europe/Budapest';

    /** A site-zone datetime value: `$local` is wall time, `Y-m-d H:i:s`. */
    private static function local(string $local): Value
    {
        $zone = new \DateTimeZone(self::ZONE);
        $instant = new \DateTimeImmutable($local, $zone);

        return Value::datetime($instant->format('Y-m-d\TH:i:sP'), '', $zone);
    }

    private function holds(string $key, string $operator, mixed $value, string $now, ?Value $subject = null): bool
    {
        $facts = new ValueContext([
            'day' => Value::date('2026-12-15', ''),
            'closes' => $subject ?? self::local('2026-12-15 18:00:00'),
            'open' => Value::none(ValueType::Datetime),
            Facts::NOW => self::local($now),
        ]);
        $condition = ['match' => 'all', 'items' => [['key' => $key, 'operator' => $operator, 'value' => $value]]];

        return (new ConditionEvaluator())->holds(
            (new ConditionParser())->parse($condition, new ContextScope($facts)),
            $facts
        );
    }

    /** @return iterable<string, array{string, string, bool}> now, operator against the day 2026-12-15, expected */
    public static function dayAgainstNow(): iterable
    {
        // `day` is a Date; `now` is a datetime: the comparison is on the day.
        yield 'now >= D at 00:00 of D' => ['2026-12-15 00:00:00', 'lessOrEqual', true];
        yield 'now >= D the second before D' => ['2026-12-14 23:59:59', 'lessOrEqual', false];
        yield 'now <= D at 23:59:59 of D' => ['2026-12-15 23:59:59', 'greaterOrEqual', true];
        yield 'now <= D at 00:00 of the day after' => ['2026-12-16 00:00:00', 'greaterOrEqual', false];
        yield 'now = D all day' => ['2026-12-15 17:30:00', 'equals', true];
        yield 'now < D on D is false' => ['2026-12-15 08:00:00', 'greaterThan', false];
        yield 'now > D on D is false' => ['2026-12-15 08:00:00', 'lessThan', false];
    }

    /** @dataProvider dayAgainstNow */
    public function testADayKeepsItsWholeDayMeaningAgainstNow(string $now, string $operator, bool $expected): void
    {
        // The key reads `day` (a Date); `now` is the reference. `lessOrEqual` on the day is "day <= now".
        self::assertSame(
            $expected,
            $this->holds('day', $operator, ['ref' => 'now'], $now)
        );
    }

    public function testTheDayOfNowIsTheSiteLocalDayAcrossDaylightSavingChanges(): void
    {
        // 2027-03-28 is 23 hours long in Budapest; 2026-10-25 25 hours. The day is the wall date either way.
        $day = static fn (string $day): ValueContext => new ValueContext([
            'day' => Value::date($day, ''),
            Facts::NOW => self::local($day . ' 23:59:59'),
        ]);
        foreach (['2027-03-28', '2026-10-25'] as $date) {
            $facts = $day($date);
            $condition = (new ConditionParser())->parse(
                ['match' => 'all', 'items' => [['key' => 'day', 'operator' => 'equals', 'value' => ['ref' => 'now']]]],
                new ContextScope($facts)
            );
            self::assertTrue((new ConditionEvaluator())->holds($condition, $facts), $date);
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function instants(): iterable
    {
        // `closes` is the datetime 2026-12-15 18:00 +01:00; the condition reads `closes <op> now`.
        yield 'closes after now' => ['2026-12-15 17:59:59', 'greaterThan', true];
        yield 'closes at now' => ['2026-12-15 18:00:00', 'equals', true];
        yield 'closes not after now at its instant' => ['2026-12-15 18:00:00', 'greaterThan', false];
        yield 'closes before now' => ['2026-12-15 18:00:01', 'lessThan', true];
        yield 'a later day' => ['2026-12-16 00:00:00', 'lessThan', true];
    }

    /** @dataProvider instants */
    public function testTwoDatetimesCompareAsInstants(string $now, string $operator, bool $expected): void
    {
        self::assertSame($expected, $this->holds('closes', $operator, ['ref' => 'now'], $now));
    }

    public function testInstantsCompareAcrossDifferentOffsets(): void
    {
        // 18:00+01:00 is 17:00Z: the same instant written two ways is equal, never compared as strings.
        self::assertTrue($this->holds('closes', 'equals', '2026-12-15T17:00:00Z', '2026-12-15 12:00:00'));
        self::assertTrue($this->holds('closes', 'lessThan', '2026-12-15T17:00:01Z', '2026-12-15 12:00:00'));
        // Across the change: 09:00+02:00 in summer is 07:00Z; the string order would say it is later than 08:00+01:00.
        $summer = self::local('2026-07-01 09:00:00');
        self::assertTrue($this->holds('closes', 'lessThan', '2026-07-01T08:30:00Z', '2026-07-01 00:00:00', $summer));
        self::assertFalse($this->holds('closes', 'lessThan', '2026-07-01T07:00:00Z', '2026-07-01 00:00:00', $summer));
    }

    public function testADatetimeKeyAgainstADateLiteralComparesTheDay(): void
    {
        // closes is on 2026-12-15 at 18:00: not after 2026-12-15, before 2026-12-16.
        self::assertFalse($this->holds('closes', 'greaterThan', '2026-12-15', '2026-12-01 00:00:00'));
        self::assertTrue($this->holds('closes', 'equals', '2026-12-15', '2026-12-01 00:00:00'));
        self::assertTrue($this->holds('closes', 'lessThan', '2026-12-16', '2026-12-01 00:00:00'));
    }

    public function testADateKeyAgainstADatetimeLiteralComparesTheLiteralsDayAsWritten(): void
    {
        // The day is the date part as written, whatever the offset says in another zone.
        self::assertTrue($this->holds('day', 'equals', '2026-12-15T23:30:00+01:00', '2026-12-01 00:00:00'));
        self::assertTrue($this->holds('day', 'lessThan', '2026-12-16T00:00:00+01:00', '2026-12-01 00:00:00'));
        self::assertTrue($this->holds('day', 'greaterThan', '2026-12-14T23:59:59-10:00', '2026-12-01 00:00:00'));
    }

    public function testADatetimeMovesByWallTimeInItsZone(): void
    {
        // now 2027-03-27 12:00 (+01:00) + 1 day is 2027-03-28 12:00, which is +02:00: 10:00Z, not 11:00Z.
        $now = self::local('2027-03-27 12:00:00');
        self::assertSame('2027-03-28T12:00:00+02:00', DateReference::now(0, 1)->fromInstant($now));
        // Years move the calendar year, 29 February to 28 February, the wall time kept.
        $leap = self::local('2028-02-29 09:15:00');
        self::assertSame('2027-02-28T09:15:00+01:00', DateReference::now(-1)->fromInstant($leap));
        self::assertSame('2029-02-28T09:15:00+01:00', DateReference::now(1)->fromInstant($leap));
    }

    public function testNoMoveLeavesTheInstantAloneEvenInTheRepeatedHour(): void
    {
        // 02:30 happens twice on 2026-10-25 in Budapest: first at +02:00, then at +01:00. Plain `now` is the second.
        $second = Value::datetime('2026-10-25T02:30:00+01:00', '', new \DateTimeZone(self::ZONE));
        $first = Value::datetime('2026-10-25T02:30:00+02:00', '', new \DateTimeZone(self::ZONE));

        self::assertSame('2026-10-25T02:30:00+01:00', DateReference::now()->fromInstant($second));
        self::assertSame('2026-10-25T02:30:00+02:00', DateReference::now()->fromInstant($first));
    }

    public function testAMoveOnADatetimeWithNoZoneKeepsItsOffset(): void
    {
        self::assertSame(
            '2027-03-28T12:00:00+01:00',
            DateReference::now(0, 1)->fromInstant(Value::datetime('2027-03-27T12:00:00+01:00', ''))
        );
    }

    public function testClosesBeforeNowPlusOneDayUsesTheMovedInstant(): void
    {
        $facts = new ValueContext([
            'closes' => self::local('2027-03-28 12:00:00'),
            Facts::NOW => self::local('2027-03-27 12:00:00'),
        ]);
        $condition = (new ConditionParser())->parse(
            [
                'match' => 'all',
                'items' => [['key' => 'closes', 'operator' => 'equals', 'value' => ['ref' => 'now', 'days' => 1]]],
            ],
            new ContextScope($facts)
        );

        self::assertTrue((new ConditionEvaluator())->holds($condition, $facts));
    }

    public function testADatetimeKeyIsAReferenceTargetAndAnEmptyOneIsUnknown(): void
    {
        $facts = new ValueContext([
            'closes' => self::local('2026-12-15 18:00:00'),
            'open' => Value::none(ValueType::Datetime),
            'day' => Value::date('2026-12-15', ''),
        ]);
        $parser = new ConditionParser();
        $evaluator = new ConditionEvaluator();
        $reference = static fn (string $key, string $target): array => [
            'match' => 'all',
            'items' => [['key' => $key, 'operator' => 'lessThan', 'value' => ['key' => $target, 'days' => -7]]],
        ];

        // A date against a datetime key moved by days: the day compare (15 December against 8 December).
        $scope = new ContextScope($facts);
        self::assertFalse($evaluator->holds($parser->parse($reference('day', 'closes'), $scope), $facts));
        $later = ['key' => 'day', 'operator' => 'greaterThan', 'value' => ['key' => 'closes', 'days' => -7]];
        self::assertTrue($evaluator->holds($parser->parse(['match' => 'all', 'items' => [$later]], $scope), $facts));
        self::assertFalse($evaluator->holds($parser->parse($reference('closes', 'open'), $scope), $facts));
        self::assertFalse($evaluator->holds($parser->parse($reference('open', 'closes'), $scope), $facts));
    }

    public function testEmptyReadsAMissingDatetime(): void
    {
        $facts = new ValueContext(['open' => Value::none(ValueType::Datetime)]);
        $condition = (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'open', 'operator' => 'empty']]],
            new ContextScope($facts)
        );

        self::assertTrue((new ConditionEvaluator())->holds($condition, $facts));
    }

    public function testContainsIsRefusedOnADatetime(): void
    {
        $facts = new ValueContext(['closes' => self::local('2026-12-15 18:00:00')]);

        $this->expectException(SystemFailure::class);
        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'closes', 'operator' => 'contains', 'value' => '2026-12-15']]],
            new ContextScope($facts)
        );
    }

    public function testMicrosecondsAreDropped(): void
    {
        self::assertSame('2026-12-15T18:00:00+01:00', self::local('2026-12-15 18:00:00.999999')->data);
    }

    /** @return iterable<string, array{string}> */
    public static function literals(): iterable
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's own fixture
        $file = file_get_contents(__DIR__ . '/../../fixtures/datetime-literals.json');
        self::assertIsString($file);
        $literals = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($literals);
        foreach ($literals['valid'] as $literal) {
            yield 'valid ' . $literal => [$literal];
        }
    }

    /** @dataProvider literals */
    public function testTheParserTakesADatetimeLiteralOnADateAndOnADatetimeKey(string $literal): void
    {
        $facts = new ValueContext([
            'day' => Value::date('2026-12-15', ''),
            'closes' => self::local('2026-12-15 18:00:00'),
        ]);
        foreach (['day', 'closes'] as $key) {
            $condition = (new ConditionParser())->parse(
                ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'lessThan', 'value' => $literal]]],
                new ContextScope($facts)
            );
            self::assertSame($literal, $condition->items[0]->value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLiterals(): iterable
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's own fixture
        $file = file_get_contents(__DIR__ . '/../../fixtures/datetime-literals.json');
        self::assertIsString($file);
        $literals = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($literals);
        foreach ($literals['invalid'] as $literal) {
            // A bare day is no datetime literal, but a date key and a datetime key both take it as a day.
            if ($literal === '2026-12-01') {
                continue;
            }
            yield 'invalid ' . $literal => [$literal];
        }
    }

    /** @dataProvider invalidLiterals */
    public function testEveryInvalidFixtureLiteralIsRefusedAsAValue(string $literal): void
    {
        $facts = new ValueContext([
            'day' => Value::date('2026-12-15', ''),
            'closes' => self::local('2026-12-15 18:00:00'),
        ]);
        foreach (['day', 'closes'] as $key) {
            try {
                (new ConditionParser())->parse(
                    ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $literal]]],
                    new ContextScope($facts)
                );
                self::fail($key . ' ' . $literal);
            } catch (SystemFailure $failure) {
                self::assertStringEndsWith('invalid_condition ' . $key, $failure->getMessage());
            }
        }
    }

    public function testTheParserRefusesAnInvalidLiteralAndAnInstantOnAnotherType(): void
    {
        $facts = new ValueContext([
            'closes' => self::local('2026-12-15 18:00:00'),
            'note' => Value::none(ValueType::Text),
        ]);
        $parse = static fn (string $key, string $value) => (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => $key, 'operator' => 'equals', 'value' => $value]]],
            new ContextScope($facts)
        );
        $refused = ['2026-02-30T12:00:00+01:00', '2026-12-01T24:00:00+01:00', '2026-12-01T18:00:00'];
        foreach ($refused as $bad) {
            try {
                $parse('closes', $bad);
                self::fail($bad);
            } catch (SystemFailure $failure) {
                self::assertStringEndsWith('invalid_condition closes', $failure->getMessage());
            }
        }
        // A text key takes any text: the literal is only an instant where the key is one.
        self::assertSame('2026-12-01T24:00:00+01:00', $parse('note', '2026-12-01T24:00:00+01:00')->items[0]->value);
    }
}
