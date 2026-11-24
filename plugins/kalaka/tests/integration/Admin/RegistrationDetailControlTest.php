<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Admin;

/** B5-2: the edit control the detail read names for each field type, and the display text of a consent. */
final class RegistrationDetailControlTest extends RegistrationDetailTestCase
{
    /** @return array<string, array<string, mixed>> the fields by key */
    private function fields(): array
    {
        $options = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];
        $this->detailForm(
            $this->field('choice', 'size', ['options' => $options])
            . $this->field('choice', 'extras', ['options' => $options, 'multiple' => true])
            . $this->field('consent', 'agree')
            . $this->field('consent', 'declined')
            . $this->field('number', 'qty')
            . $this->field('text', 'note')
            . $this->field('date', 'born')
        );
        $this->addRegistration('00A0');
        $this->addValue('00A0', 'name', 'Teszt Elek');
        $this->addValue('00A0', 'size', 'a');
        $this->addValue('00A0', 'extras', '["a","b"]');
        $this->addValue('00A0', 'agree', '1');
        $this->addValue('00A0', 'declined', '0');
        $fields = [];
        foreach ($this->detail($this->admin->userId)['fields'] as $field) {
            $fields[(string) $field['key']] = $field;
        }

        return $fields;
    }

    public function testEachFieldNamesItsSimpleControlAndAChoiceSaysWhetherItTakesSeveral(): void
    {
        $fields = $this->fields();

        $controls = [
            'name' => 'text',
            'size' => 'choice',
            'extras' => 'multi_choice',
            'agree' => 'consent',
            'qty' => 'number',
            'note' => 'text',
            'born' => 'text',
        ];
        foreach ($controls as $key => $control) {
            self::assertSame($control, $fields[$key]['edit']['control'], $key);
        }
        self::assertFalse($fields['size']['edit']['multiple']);
        self::assertTrue($fields['extras']['edit']['multiple']);
        self::assertSame(['a', 'b'], $fields['extras']['edit']['answer']['value']);
        self::assertArrayNotHasKey('multiple', $fields['agree']['edit']);
        self::assertArrayNotHasKey('multiple', $fields['name']['edit']);
    }

    public function testAConsentsTextIsTheWordsTheGridUsesNotTheStoredDigit(): void
    {
        $fields = $this->fields();

        self::assertSame('Igen', $fields['agree']['text']);
        self::assertSame('Nem', $fields['declined']['text']);
        self::assertSame('1', $fields['agree']['value']);
        self::assertSame('1', $fields['agree']['edit']['expected'], 'The stored value is what the act compares.');
    }
}
