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
use Kalaka\Forms\Engine\Condition\DeclaredScope;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/**
 * `time.now` as the left key of a comparison: "show this until 2026-12-01 18:00".
 */
final class ConditionLeftNowTest extends TestCase
{
    private const ZONE = 'Europe/Budapest';

    private static function local(string $local): Value
    {
        $zone = new \DateTimeZone(self::ZONE);
        $instant = new \DateTimeImmutable($local, $zone);

        return Value::datetime($instant->format('Y-m-d\TH:i:sP'), '', $zone);
    }

    private function holds(string $operator, mixed $value, string $now): bool
    {
        $facts = new ValueContext([
            'closes' => self::local('2026-12-15 18:00:00'),
            Facts::NOW => self::local($now),
        ]);
        $condition = ['match' => 'all', 'items' => [['key' => Facts::NOW, 'operator' => $operator, 'value' => $value]]];

        return (new ConditionEvaluator())->holds(
            (new ConditionParser())->parse($condition, new ContextScope($facts)),
            $facts
        );
    }

    /** @return iterable<string, array{string, string, bool}> now, operator against 2026-12-01T18:00:00+01:00 */
    public static function instants(): iterable
    {
        yield 'before the instant' => ['2026-12-01 17:59:59', 'lessThan', true];
        yield 'at the instant, lessThan' => ['2026-12-01 18:00:00', 'lessThan', false];
        yield 'at the instant, lessOrEqual' => ['2026-12-01 18:00:00', 'lessOrEqual', true];
        yield 'after the instant' => ['2026-12-01 18:00:01', 'lessThan', false];
        yield 'after the instant, greaterThan' => ['2026-12-01 18:00:01', 'greaterThan', true];
    }

    /** @dataProvider instants */
    public function testNowComparesWithADatetimeLiteralAsInstants(string $now, string $operator, bool $expected): void
    {
        self::assertSame($expected, $this->holds($operator, '2026-12-01T18:00:00+01:00', $now));
    }

    public function testTheLiteralsOffsetIsWhatCounts(): void
    {
        // 17:00Z is 18:00 in Budapest in December.
        self::assertTrue($this->holds('lessThan', '2026-12-01T17:00:00Z', '2026-12-01 17:59:59'));
        self::assertFalse($this->holds('lessThan', '2026-12-01T17:00:00Z', '2026-12-01 18:00:00'));
    }

    /** @return iterable<string, array{string, string, bool}> now, operator against the day 2026-12-15 */
    public static function days(): iterable
    {
        yield 'lessThan D holds until D starts' => ['2026-12-14 23:59:59', 'lessThan', true];
        yield 'lessThan D is false at the start of D' => ['2026-12-15 00:00:00', 'lessThan', false];
        yield 'lessOrEqual D holds to the end of D' => ['2026-12-15 23:59:59', 'lessOrEqual', true];
        yield 'lessOrEqual D is false the next day' => ['2026-12-16 00:00:00', 'lessOrEqual', false];
        yield 'greaterOrEqual D from its start' => ['2026-12-15 00:00:00', 'greaterOrEqual', true];
        yield 'equals D all day' => ['2026-12-15 17:30:00', 'equals', true];
    }

    /** @dataProvider days */
    public function testADayLiteralKeepsItsWholeDay(string $now, string $operator, bool $expected): void
    {
        self::assertSame($expected, $this->holds($operator, '2026-12-15', $now));
    }

    public function testAValueSideReferenceIsAllowed(): void
    {
        self::assertTrue($this->holds('lessThan', ['key' => 'closes'], '2026-12-15 17:59:59'));
        self::assertFalse($this->holds('lessThan', ['key' => 'closes'], '2026-12-15 18:00:00'));
        self::assertTrue($this->holds('lessThan', ['ref' => 'now', 'days' => 1], '2026-12-15 17:59:59'));
        self::assertFalse($this->holds('lessThan', ['ref' => 'now'], '2026-12-15 17:59:59'));
    }

    public function testItParsesInEveryScopeAndIsReadAsNow(): void
    {
        $condition = ['match' => 'all', 'items' => [
            ['key' => Facts::NOW, 'operator' => 'lessThan', 'value' => '2026-12-01T18:00:00+01:00'],
        ]];

        $parsed = (new ConditionParser())->parse($condition, new DeclaredScope(['x' => ValueType::Text]));

        self::assertSame([Facts::NOW], $parsed->keys());
    }

    /** @return iterable<string, array{mixed}> */
    public static function refused(): iterable
    {
        yield 'a literal without an offset' => ['2026-12-01T18:00:00'];
        yield 'a non-date' => ['tomorrow'];
        yield 'a number' => [5];
        yield 'time.now as the value-side key' => [['key' => Facts::NOW]];
        yield 'an unknown reference' => [['ref' => 'today']];
    }

    /** @dataProvider refused */
    public function testARefusedShapeIsADefinitionProblem(mixed $value): void
    {
        $this->expectException(SystemFailure::class);

        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => Facts::NOW, 'operator' => 'lessThan', 'value' => $value]]],
            new DeclaredScope([])
        );
    }

    public function testContainsIsRefused(): void
    {
        $this->expectException(SystemFailure::class);

        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => Facts::NOW, 'operator' => 'contains', 'value' => '2026-12-01']]],
            new DeclaredScope([])
        );
    }

    public function testABareNowIsAnOrdinaryKey(): void
    {
        $this->expectException(SystemFailure::class);

        (new ConditionParser())->parse(
            ['match' => 'all', 'items' => [['key' => 'now', 'operator' => 'lessThan', 'value' => '2026-12-01']]],
            new DeclaredScope([])
        );
    }
}
