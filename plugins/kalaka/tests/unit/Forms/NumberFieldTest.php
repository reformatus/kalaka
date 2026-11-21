<?php

declare(strict_types=1);

namespace Kalaka\Tests\Unit\Forms;

use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\Value;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Forms\Engine\FieldType\NumberFieldType;
use PHPUnit\Framework\TestCase;

/** The number field's settings, answers, refusals and value text (topic 7). */
final class NumberFieldTest extends TestCase
{
    /** @param array<string, mixed> $attributes */
    private static function answer(string $sent, array $attributes = []): ?string
    {
        return (new NumberFieldType())->answer(new FieldTypeInput($sent, $attributes))->value;
    }

    /** @param array<string, mixed> $attributes */
    private static function error(string $sent, array $attributes = []): ?string
    {
        return (new NumberFieldType())->answer(new FieldTypeInput($sent, $attributes))->error;
    }

    public function testAWholeOrDecimalNumberIsStoredCanonically(): void
    {
        self::assertSame('5000', self::answer('5000'));
        self::assertSame('5000', self::answer(' 5000 '));
        self::assertSame('5.5', self::answer('05.50', ['decimals' => 2]));
        self::assertSame('0', self::answer('-0', ['decimals' => 1]));
        self::assertSame('0', self::answer('0'));
        self::assertSame('0.5', self::answer('.5', ['decimals' => 1]));
        self::assertSame('-0.5', self::answer('-.5', ['decimals' => 1]));
        self::assertSame('123456789012', self::answer('123456789012'));
        self::assertTrue((new NumberFieldType())->answer(new FieldTypeInput('', []))->value === null);
    }

    public function testTheBoundsAndTheStepCountedFromTheMinimum(): void
    {
        $bounds = ['min' => '1000', 'step' => '500', 'max' => '100000'];
        self::assertSame('1000', self::answer('1000', $bounds));
        self::assertSame('1500', self::answer('1500', $bounds));
        self::assertSame('100000', self::answer('100000', $bounds));
        self::assertSame('Legalább 1' . "\u{00A0}" . '000 legyen.', self::error('500', $bounds));
        self::assertSame(
            'Csak ilyen értéket írhatsz ide: 1' . "\u{00A0}" . '000, 1' . "\u{00A0}" . '500, 2'
                . "\u{00A0}" . '000 és így tovább.',
            self::error('1200', $bounds)
        );
        self::assertSame('Legfeljebb 100' . "\u{00A0}" . '000 legyen.', self::error('100500', $bounds));
    }

    public function testTheStepRefusalListsOnlyTheValuesMaxLeaves(): void
    {
        self::assertSame(
            'Csak ilyen értéket írhatsz ide: 1' . "\u{00A0}" . '000 vagy' . "\u{00A0}" . '2'
                . "\u{00A0}" . '000.',
            self::error('1500', ['min' => '1000', 'step' => '1000', 'max' => '2000'])
        );
        self::assertSame(
            'Csak ilyen értéket írhatsz ide: 1' . "\u{00A0}" . '000, 1' . "\u{00A0}" . '500 vagy'
                . "\u{00A0}" . '2' . "\u{00A0}" . '000.',
            self::error('1200', ['min' => '1000', 'step' => '500', 'max' => '2000'])
        );
        self::assertSame(
            'Csak ilyen értéket írhatsz ide: 1' . "\u{00A0}" . '000, 2' . "\u{00A0}" . '000, 3'
                . "\u{00A0}" . '000 és így tovább.',
            self::error('1500', ['min' => '1000', 'step' => '1000', 'max' => '4000'])
        );
    }

    public function testWithoutAMinimumTheStepCountsFromZero(): void
    {
        $bounds = ['step' => '500'];
        self::assertSame('0', self::answer('0', $bounds));
        self::assertSame('1500', self::answer('1500', $bounds));
        self::assertSame('Az érték 500 többszöröse legyen.', self::error('1200', $bounds));
    }

    public function testTheRefusalsAreNeutralAndCarryTheFieldsPrefixAndSuffix(): void
    {
        self::assertSame('Csak egész számot írhatsz ide.', self::error('12,5'));
        self::assertSame('Csak számot írhatsz ide.', self::error('abc', ['decimals' => 1]));
        self::assertSame('Csak számot írhatsz ide.', self::error('1e3'));
        self::assertSame('Csak számot írhatsz ide.', self::error('1e3', ['decimals' => 1]));
        self::assertSame('Csak egész számot írhatsz ide.', self::error('.5'));
        self::assertSame(
            'Legfeljebb 1 tizedesjegyet írhatsz ide.',
            self::error('1.25', ['decimals' => 1])
        );
        self::assertSame(
            'Legalább 1' . "\u{00A0}" . '000' . "\u{00A0}" . 'Ft legyen.',
            self::error('500', ['min' => '1000', 'suffix' => 'Ft'])
        );
        self::assertSame(
            'Legfeljebb 100' . "\u{00A0}" . '000' . "\u{00A0}" . 'Ft legyen.',
            self::error('100500', ['max' => '100000', 'suffix' => 'Ft'])
        );
        self::assertSame(
            'Legalább ' . "\u{2212}" . '500 legyen.',
            self::error('-600', ['min' => '-500'])
        );
    }

    public function testTheValueIsANumberWithItsTextAndT5Typography(): void
    {
        $type = new NumberFieldType();
        self::assertSame(ValueType::Number, $type->valueType());
        self::assertEquals(
            Value::number('5000', '5' . "\u{00A0}" . '000' . "\u{00A0}" . 'Ft'),
            $type->value(new FieldTypeInput('5000', ['suffix' => 'Ft']))
        );
        self::assertEquals(
            Value::number('-1500.5', "\u{2212}" . '1' . "\u{00A0}" . '500,5'),
            $type->value(new FieldTypeInput('-1500.5', []))
        );
        self::assertEquals(
            Value::number('1500', '€' . "\u{00A0}" . '1' . "\u{00A0}" . '500'),
            $type->value(new FieldTypeInput('1500', ['prefix' => '€']))
        );
        self::assertTrue($type->value(new FieldTypeInput('', []))->isEmpty());
        self::assertTrue($type->value(new FieldTypeInput('12,5', []))->isEmpty());
    }

    public function testPrefillIsTheStoredNumberWhenTheCurrentSettingsAcceptIt(): void
    {
        $type = new NumberFieldType();
        self::assertSame('5000', $type->prefill(new FieldTypeInput('5000', ['min' => '1000'])));
        self::assertNull($type->prefill(new FieldTypeInput('500.5', ['decimals' => 0])));
        self::assertNull($type->prefill(new FieldTypeInput('500', ['min' => '1000'])));
        self::assertNull($type->prefill(new FieldTypeInput('1200', ['step' => '500'])));
    }

    public function testInvalidSettingsAreRefused(): void
    {
        self::assertNull(NumberFieldType::readSettings(['decimals' => 9]));
        self::assertNull(NumberFieldType::readSettings(['decimals' => 0, 'step' => '0']));
        self::assertNull(NumberFieldType::readSettings(['decimals' => 0, 'min' => '0.5']));
        self::assertNull(NumberFieldType::readSettings(['decimals' => 2, 'min' => '2', 'max' => '1']));
        self::assertNotNull(NumberFieldType::readSettings(['decimals' => 2, 'min' => '1.50', 'step' => '0.5']));
    }
}
