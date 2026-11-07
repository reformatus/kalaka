<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Foundation\Money;

use Kalaka\Foundation\Api\Error\InvalidInput;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Money\Currency;
use Kalaka\Foundation\Api\Money\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private const MAX = '999999999999999999.99999999';
    private const MIN = '-999999999999999999.99999999';

    private static function huf(int|string $amount): Money
    {
        return Money::of($amount, Currency::HUF);
    }

    /** @return iterable<string, array{int|string}> */
    public static function acceptedAmounts(): iterable
    {
        yield 'int' => [48000];
        yield 'integer text' => ['48000'];
        yield 'negative fraction' => ['-0.5'];
        yield 'smallest unit' => ['0.00000001'];
        yield 'maximum' => [self::MAX];
        yield 'minimum' => [self::MIN];
        yield 'leading zeros' => ['007'];
        yield 'leading zeros do not count toward the digits' => ['0000000000000000000001'];
        yield 'negative zero' => ['-0'];
    }

    /** @dataProvider acceptedAmounts */
    public function testOfAcceptsTheGrammar(int|string $amount): void
    {
        self::assertInstanceOf(Money::class, self::huf($amount));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedAmounts(): iterable
    {
        yield 'exponent' => ['1e3'];
        yield 'plus sign' => ['+1'];
        yield 'comma' => ['1,5'];
        yield 'thousands separator' => ['1 000'];
        yield 'no integer part' => ['.5'];
        yield 'no fraction digits' => ['5.'];
        yield 'nine decimals' => ['0.000000001'];
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'nineteen integer digits' => ['1000000000000000000'];
        yield 'nineteen integer digits negative' => ['-1000000000000000000'];
        yield 'trailing newline' => ["5\n"];
        yield 'surrounding spaces' => [' 5'];
        yield 'two dots' => ['1.2.3'];
        yield 'double minus' => ['--1'];
        yield 'minus only' => ['-'];
    }

    /** @dataProvider rejectedAmounts */
    public function testOfRejectsAnythingElse(string $amount): void
    {
        $this->expectException(InvalidInput::class);

        self::huf($amount);
    }

    public function testOfRejectsAnIntegerBeyondEighteenDigits(): void
    {
        $this->expectException(InvalidInput::class);

        self::huf(PHP_INT_MAX);
    }

    public function testInvalidInputCarriesNoAddedContext(): void
    {
        try {
            self::huf('abc');
            self::fail('Expected InvalidInput.');
        } catch (InvalidInput $e) {
            self::assertSame((new InvalidInput())->getMessage(), $e->getMessage());
        }
    }

    /** @return iterable<string, array{int|string, string}> */
    public static function amountForms(): iterable
    {
        yield 'int' => [48000, '48000.00000000'];
        yield 'one decimal' => ['1.5', '1.50000000'];
        yield 'negative fraction' => ['-0.5', '-0.50000000'];
        yield 'negative zero' => ['-0', '0.00000000'];
        yield 'negative zero with decimals' => ['-0.00', '0.00000000'];
        yield 'leading zeros' => ['007', '7.00000000'];
        yield 'smallest unit' => ['0.00000001', '0.00000001'];
        yield 'maximum' => [self::MAX, self::MAX];
        yield 'minimum' => [self::MIN, self::MIN];
        yield 'zero int' => [0, '0.00000000'];
    }

    /** @dataProvider amountForms */
    public function testAmountIsTheDecimalTextForm(int|string $input, string $expected): void
    {
        self::assertSame($expected, self::huf($input)->amount());
    }

    public function testZero(): void
    {
        $zero = Money::zero(Currency::HUF);

        self::assertSame('0.00000000', $zero->amount());
        self::assertSame(Currency::HUF, $zero->currency());
        self::assertTrue($zero->isEqualTo(self::huf('-0')));
    }

    public function testPlusIsExact(): void
    {
        self::assertTrue(self::huf('0.1')->plus(self::huf('0.2'))->isEqualTo(self::huf('0.3')));
        self::assertSame('0.30000000', self::huf('0.1')->plus(self::huf('0.2'))->amount());
    }

    public function testMinusIsExact(): void
    {
        self::assertSame('-0.10000000', self::huf('0.2')->minus(self::huf('0.3'))->amount());
        self::assertSame('0.00000000', self::huf('5')->minus(self::huf('5'))->amount());
    }

    public function testOperationsKeepTheCurrencyAndReturnNewValues(): void
    {
        $a = self::huf('10');
        $sum = $a->plus(self::huf('5'));

        self::assertSame('10.00000000', $a->amount());
        self::assertSame('15.00000000', $sum->amount());
        self::assertSame(Currency::HUF, $sum->currency());
    }

    /** @return iterable<string, array{string, string}> */
    public static function negations(): iterable
    {
        yield 'positive' => ['48000', '-48000.00000000'];
        yield 'negative' => ['-0.5', '0.50000000'];
        yield 'zero' => ['0', '0.00000000'];
        yield 'maximum' => [self::MAX, self::MIN];
        yield 'minimum' => [self::MIN, self::MAX];
    }

    /** @dataProvider negations */
    public function testNegated(string $input, string $expected): void
    {
        self::assertSame($expected, self::huf($input)->negated()->amount());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function comparisons(): iterable
    {
        yield 'less' => ['1', '2', -1];
        yield 'equal with different spelling' => ['1.5', '1.50', 0];
        yield 'greater' => ['2', '1', 1];
        yield 'negative less' => ['-2', '-1', -1];
        yield 'negative greater than more negative' => ['-1', '-2', 1];
        yield 'negative below positive' => ['-0.00000001', '0.00000001', -1];
        yield 'zero spellings' => ['0', '-0', 0];
    }

    /** @dataProvider comparisons */
    public function testComparisons(string $left, string $right, int $expected): void
    {
        $a = self::huf($left);
        $b = self::huf($right);

        self::assertSame($expected, $a->compareTo($b));
        self::assertSame($expected === 0, $a->isEqualTo($b));
        self::assertSame($expected < 0, $a->isLessThan($b));
        self::assertSame($expected > 0, $a->isGreaterThan($b));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function signs(): iterable
    {
        yield 'positive' => ['48000', false, true];
        yield 'smallest positive' => ['0.00000001', false, true];
        yield 'negative' => ['-0.5', true, false];
        yield 'zero' => ['0', false, false];
        yield 'negative zero' => ['-0', false, false];
    }

    /** @dataProvider signs */
    public function testSigns(string $amount, bool $negative, bool $positive): void
    {
        self::assertSame($negative, self::huf($amount)->isNegative());
        self::assertSame($positive, self::huf($amount)->isPositive());
    }

    /** @return iterable<string, array{callable(): Money}> */
    public static function outOfRangeResults(): iterable
    {
        yield 'plus above the maximum' => [static fn (): Money => self::huf(self::MAX)->plus(self::huf('0.00000001'))];
        yield 'plus below the minimum' => [
            static fn (): Money => self::huf(self::MIN)->plus(self::huf('-0.00000001')),
        ];
        yield 'minus below the minimum' => [
            static fn (): Money => self::huf(self::MIN)->minus(self::huf('0.00000001')),
        ];
        yield 'minus above the maximum' => [static fn (): Money => self::huf(self::MAX)->minus(self::huf('-1'))];
        yield 'rounding up past the maximum' => [static fn (): Money => self::huf(self::MAX)->roundedUpToPayableUnit()];
    }

    /**
     * @dataProvider outOfRangeResults
     * @param callable(): Money $operation
     */
    public function testAResultOutsideTheRangeIsASystemFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected SystemFailure.');
        } catch (SystemFailure $e) {
            self::assertSame((new SystemFailure())->getMessage(), $e->getMessage());
        }
    }

    public function testTheBoundariesThemselvesAreReachable(): void
    {
        self::assertSame(
            self::MAX,
            self::huf('999999999999999999.99999998')->plus(self::huf('0.00000001'))->amount()
        );
        self::assertSame(
            self::MIN,
            self::huf('-999999999999999999.99999998')->minus(self::huf('0.00000001'))->amount()
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function zeroBoundary(): iterable
    {
        yield 'zero' => ['0', true];
        yield 'just below a fillér' => ['0.00999999', true];
        yield 'just below a fillér, negative' => ['-0.00999999', true];
        yield 'one fillér' => ['0.01', false];
        yield 'one fillér, negative' => ['-0.01', false];
        yield 'forints' => ['48000', false];
        yield 'smallest unit' => ['0.00000001', true];
    }

    /** @dataProvider zeroBoundary */
    public function testCountsAsZero(string $amount, bool $expected): void
    {
        self::assertSame($expected, self::huf($amount)->countsAsZero());
    }

    /** @return iterable<string, array{string, string}> */
    public static function payableUnit(): iterable
    {
        yield 'whole forints stay' => ['1000', '1000'];
        yield 'below a fillér is dropped' => ['1000.004', '1000'];
        yield 'just below a fillér is dropped' => ['1000.00999999', '1000'];
        yield 'one fillér rounds up' => ['1000.01', '1001'];
        yield 'half rounds up' => ['1000.5', '1001'];
        yield 'below a fillér is zero' => ['0.009', '0'];
        yield 'negative rounds toward zero' => ['-10.5', '-10'];
        yield 'negative below a fillér is zero' => ['-0.004', '0'];
        yield 'zero' => ['0', '0'];
        yield 'large' => ['48000.01', '48001'];
    }

    /** @dataProvider payableUnit */
    public function testRoundedUpToPayableUnit(string $amount, string $expected): void
    {
        $rounded = self::huf($amount)->roundedUpToPayableUnit();

        self::assertTrue($rounded->isEqualTo(self::huf($expected)));
        self::assertSame(Currency::HUF, $rounded->currency());
    }

    public function testRoundedUpToPayableUnitHasTheDecimalTextForm(): void
    {
        self::assertSame('1001.00000000', self::huf('1000.5')->roundedUpToPayableUnit()->amount());
        self::assertSame('0.00000000', self::huf('-0.004')->roundedUpToPayableUnit()->amount());
    }

    public function testRoundingAgreesWithCountsAsZero(): void
    {
        foreach (['0.00999999', '-0.00999999', '0.004', '0'] as $amount) {
            self::assertTrue(self::huf($amount)->countsAsZero());
            self::assertTrue(self::huf($amount)->roundedUpToPayableUnit()->isEqualTo(self::huf('0')));
        }
    }

    public function testThePublicApiExposesOnlyMoneyCurrencyAndScalars(): void
    {
        $allowed = [Money::class, Currency::class, 'string', 'int', 'bool'];

        foreach ([Money::class, Currency::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isInternal()) {
                    continue;
                }

                $types = [$method->getReturnType(), ...array_map(
                    static fn (\ReflectionParameter $p): ?\ReflectionType => $p->getType(),
                    $method->getParameters(),
                )];
                foreach ($types as $type) {
                    self::assertNotNull($type, $class . '::' . $method->getName() . ' has an untyped part.');
                    $members = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
                    foreach ($members as $member) {
                        self::assertInstanceOf(\ReflectionNamedType::class, $member);
                        $name = in_array($member->getName(), ['self', 'static'], true) ? $class : $member->getName();
                        self::assertContains($name, $allowed, $class . '::' . $method->getName());
                    }
                }
            }
        }
    }

    public function testMoneyIsFinalAndReadonly(): void
    {
        $class = new \ReflectionClass(Money::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertFalse($class->getConstructor()?->isPublic());
    }
}
