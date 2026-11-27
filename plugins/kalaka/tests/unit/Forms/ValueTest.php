<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueContext;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Error\SystemFailure;
use PHPUnit\Framework\TestCase;

/** D1 to D3: values and the context that declares them. */
final class ValueTest extends TestCase
{
    public function testEachTypeHoldsItsDataAndItsText(): void
    {
        self::assertSame('-1.50', Value::number('-1.50', '-1,5')->data);
        self::assertSame('2010-11-30', Value::date('2010-11-30', 'x')->data);
        self::assertSame('Kovács Anna', Value::text('Kovács Anna', 'Kovács Anna')->data);
        self::assertSame(['a', 'b'], Value::choice(['a', 'b'], 'A, B')->data);
        self::assertSame('A, B', Value::choice(['a', 'b'], 'A, B')->text);
        self::assertSame(ValueType::Choice, Value::choice(['a'], 'A')->type);
    }

    public function testNoneIsDeclaredEmptyAndHasNoText(): void
    {
        foreach (ValueType::cases() as $type) {
            $none = Value::none($type);
            self::assertSame($type, $none->type);
            self::assertTrue($none->isEmpty());
            self::assertSame('', $none->text);
        }
    }

    public function testATextOfNoCharactersAndAChoiceOfNoOptionsAreEmpty(): void
    {
        self::assertTrue(Value::text('', '')->isEmpty());
        self::assertTrue(Value::choice([], '')->isEmpty());
        self::assertFalse(Value::text('x', 'x')->isEmpty());
        self::assertFalse(Value::number('0', '0')->isEmpty());
    }

    /** @return iterable<string, array{callable(): Value}> */
    public static function illFormed(): iterable
    {
        yield 'number with a comma' => [static fn (): Value => Value::number('1,5', '')];
        yield 'number with an exponent' => [static fn (): Value => Value::number('1e3', '')];
        yield 'number with no digits' => [static fn (): Value => Value::number('-', '')];
        yield 'date not on the calendar' => [static fn (): Value => Value::date('2010-02-30', '')];
        yield 'date in another form' => [static fn (): Value => Value::date('2010.11.30', '')];
        yield 'choice that is no list' => [static fn (): Value => Value::choice(['x' => 'a'], '')];
        yield 'choice of a non-string' => [static fn (): Value => Value::choice([1], '')];
    }

    /**
     * @dataProvider illFormed
     * @param callable(): Value $make
     */
    public function testIllFormedDataIsAProgrammingError(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public function testAContextReturnsADeclaredValueAndRefusesAnUndeclaredKey(): void
    {
        $context = new ValueContext([
            'fee' => Value::none(ValueType::Choice),
            'event.start' => Value::date('2027-01-02', 'x'),
        ]);

        self::assertSame(ValueType::Choice, $context->get('fee')->type);
        self::assertSame('2027-01-02', $context->get('event.start')->data);
        try {
            $context->get('other');
            self::fail('An undeclared key was read.');
        } catch (SystemFailure $e) {
            self::assertStringContainsString('unknown_value_key other', $e->getMessage());
        }
    }

    public function testWithGivesAnotherContextAndLeavesTheFirstAlone(): void
    {
        $first = new ValueContext();
        $second = $first->with('fee', Value::text('x', 'x'));

        self::assertSame('x', $second->get('fee')->text);
        $this->expectException(SystemFailure::class);
        $first->get('fee');
    }

    public function testAKeyIsAFieldKeyOrADottedGroupAndName(): void
    {
        new ValueContext(['a' => Value::text('', ''), 'registration.status' => Value::text('', '')]);

        foreach (['A', '1a', 'a.', 'a.b.c', 'a-b', ''] as $bad) {
            try {
                new ValueContext([$bad => Value::text('', '')]);
                self::fail('The key ' . $bad . ' was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function datetimeLiterals(): iterable
    {
        foreach (self::literals()['valid'] as $literal) {
            yield $literal => [$literal];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function badDatetimeLiterals(): iterable
    {
        foreach (self::literals()['invalid'] as $literal) {
            yield $literal => [$literal];
        }
    }

    /** @return array{valid: list<string>, invalid: list<string>} the literals PHP and the editor's script both read */
    private static function literals(): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads the test's own fixture
        $file = file_get_contents(__DIR__ . '/../../fixtures/datetime-literals.json');
        self::assertIsString($file);
        $literals = json_decode($file, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($literals);

        return $literals;
    }

    /** @dataProvider datetimeLiterals */
    public function testADatetimeLiteralIsAccepted(string $literal): void
    {
        $value = Value::datetime($literal, 'x');

        self::assertSame(ValueType::Datetime, $value->type);
        self::assertSame($literal, $value->data);
        self::assertFalse($value->isEmpty());
    }

    /** @dataProvider badDatetimeLiterals */
    public function testAnInvalidDatetimeLiteralIsRefused(string $literal): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Value::datetime($literal, 'x');
    }

    public function testADatetimeKeepsItsInstantAndItsZone(): void
    {
        $zone = new \DateTimeZone('Europe/Budapest');
        $value = Value::datetime('2026-12-01T18:00:00+01:00', 'x', $zone);

        self::assertSame($zone, $value->zone);
        self::assertSame(
            '2026-12-01T17:00:00+00:00',
            Value::instant('2026-12-01T18:00:00+01:00')?->setTimezone(new \DateTimeZone('UTC'))->format('c')
        );
        self::assertNull(Value::instant('2026-12-01'));
    }
}
