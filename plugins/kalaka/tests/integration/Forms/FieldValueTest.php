<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\FieldType;
use Kalaka\Forms\Api\FieldTypeInput;
use Kalaka\Forms\Api\ValueType;
use Kalaka\Foundation\Api\Module\Contributions;

/** AC7: the value a core field type makes of a stored answer (D9). */
final class FieldValueTest extends \WP_UnitTestCase
{
    private function type(string $name): FieldType
    {
        $type = Contributions::current()->fieldTypes()['kalaka/' . $name . '-field'] ?? null;
        self::assertNotNull($type, $name);

        return $type;
    }

    /** @param array<string, mixed> $attributes */
    private function valueOf(string $name, string $stored, array $attributes = []): \Kalaka\Forms\Api\Value
    {
        return $this->type($name)->value(new FieldTypeInput($stored, $attributes));
    }

    public function testASingleChoiceIsAChoiceOfOneWithItsLabelAsText(): void
    {
        $options = [['value' => 'a', 'label' => 'Alfa'], ['value' => 'b', 'label' => 'Béta']];

        $value = $this->valueOf('choice', 'a', ['options' => $options]);

        self::assertSame(ValueType::Choice, $this->type('choice')->valueType());
        self::assertSame(ValueType::Choice, $value->type);
        self::assertSame(['a'], $value->data);
        self::assertSame('Alfa', $value->text);
    }

    public function testAMultipleChoiceIsAChoiceOfSeveralWithTheLabelsJoinedByACommaAndASpace(): void
    {
        $options = [
            ['value' => 'a', 'label' => 'Alfa'],
            ['value' => 'b', 'label' => 'Béta'],
            ['value' => 'c', 'label' => 'Gamma'],
        ];

        $value = $this->valueOf('choice', '["a","b"]', ['options' => $options, 'multiple' => true]);

        self::assertSame(ValueType::Choice, $value->type);
        self::assertSame(['a', 'b'], $value->data);
        self::assertSame('Alfa, Béta', $value->text);
    }

    public function testTheLabelsOfAChoiceHaveTheirTagsStripped(): void
    {
        $options = [['value' => 'a', 'label' => '<strong>Alfa</strong> <em>és</em> Béta']];

        self::assertSame('Alfa és Béta', $this->valueOf('choice', 'a', ['options' => $options])->text);
    }

    public function testADateIsADateAndItsTextIsTheStoredDayForNow(): void
    {
        $value = $this->valueOf('date', '2010-11-30');

        self::assertSame(ValueType::Date, $this->type('date')->valueType());
        self::assertSame(ValueType::Date, $value->type);
        self::assertSame('2010-11-30', $value->data);
        self::assertSame('2010-11-30', $value->text);
    }

    public function testNameEmailPhoneTextAndConsentAreTextWithTheStoredStringAsDataAndText(): void
    {
        $stored = [
            'name' => 'Kovács Anna',
            'email' => 'anna@example.org',
            'phone' => '+36301234567',
            'text' => 'Egy sor',
            'consent' => '1',
        ];

        foreach ($stored as $name => $string) {
            self::assertSame(ValueType::Text, $this->type($name)->valueType(), $name);
            $value = $this->valueOf($name, $string);
            self::assertSame(ValueType::Text, $value->type, $name);
            self::assertSame($string, $value->data, $name);
            self::assertSame($string, $value->text, $name);
        }
    }

    public function testATypeContributedWithoutOverridingGetsTheTextDefaults(): void
    {
        $type = new PlainFieldType();
        $value = $type->value(new FieldTypeInput('stored', []));

        self::assertSame(ValueType::Text, $type->valueType());
        self::assertSame(ValueType::Text, $value->type);
        self::assertSame('stored', $value->data);
        self::assertSame('stored', $value->text);
    }
}
