<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Foundation\Api\Error\SystemFailure;

/**
 * The number field in a form: core's wrapper, a native number control with its bounds and affixes, the
 * server's check of its bounds and step, and its settings stored on the participant field (topic 7).
 */
final class NumberFieldFormTest extends FormsTestCase
{
    private function numberForm(): int
    {
        return $this->slotForm($this->block('kalaka/number-field', [
            'key' => 'adomany',
            'label' => 'Adomány',
            'min' => '1000',
            'step' => '500',
            'max' => '100000',
            'decimals' => 0,
            'suffix' => 'Ft',
        ]));
    }

    public function testItRendersTheNativeNumberControlWithItsBoundsAndAffix(): void
    {
        $form = $this->readForm($this->numberForm());

        $html = implode('', array_map('render_block', $this->forms->blocks($form)));

        self::assertStringContainsString('data-kalaka-field="adomany"', $html);
        self::assertStringContainsString('wp-block-kalaka-number-field', $html);
        self::assertStringContainsString('<label for="kalaka-field-adomany">Adomány</label>', $html);
        self::assertStringContainsString('<input type="number" id="kalaka-field-adomany" name="adomany"', $html);
        self::assertStringContainsString('inputmode="numeric"', $html);
        self::assertStringContainsString('min="1000"', $html);
        self::assertStringContainsString('step="500"', $html);
        self::assertStringContainsString('max="100000"', $html);
        self::assertStringContainsString(
            '<span class="kalaka-number__affix kalaka-number__suffix" aria-hidden="true">Ft</span>',
            $html
        );
    }

    public function testTheSubmitStoresTheNumberAndRefusesOneOffTheStepGrid(): void
    {
        $definition = $this->forms->definition($this->readForm($this->numberForm()));
        $slot = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

        $stored = $this->forms->validate($definition, $slot + ['adomany' => '5000'])->stored();
        self::assertSame('5000', $stored['adomany']);
        self::assertArrayNotHasKey('adomany', $this->forms->validate($definition, $slot + ['adomany' => ''])->stored());
        try {
            $this->forms->validate($definition, $slot + ['adomany' => '1200']);
            self::fail('Refused.');
        } catch (InvalidAnswers $refused) {
            self::assertSame(
                'Csak ilyen értéket írhatsz ide: 1' . "\u{00A0}" . '000, 1' . "\u{00A0}" . '500, 2'
                    . "\u{00A0}" . '000 és így tovább.',
                $refused->fields['adomany']
            );
        }
    }

    public function testTheSettingsAreStoredOnTheParticipantFieldAndTheValueTextUsesThem(): void
    {
        $form = $this->readForm($this->numberForm());
        $fields = $this->forms->participantFields($form->eventId);
        $field = array_values(array_filter($fields, static fn ($field): bool => $field->key === 'adomany'))[0] ?? null;
        self::assertNotNull($field);
        self::assertSame('kalaka/number-field', $field->type);
        self::assertSame(0, $field->settings['decimals'] ?? null);
        self::assertSame('Ft', $field->settings['suffix'] ?? null);

        $definition = $this->forms->definition($form);
        $answers = $this->forms->validate(
            $definition,
            ['name' => 'Teszt Elek', 'email' => 'teszt@example.org', 'adomany' => '5000']
        );
        self::assertSame('5000', $answers->stored()['adomany']);
    }

    public function testADecimalFieldWithNoStepRendersStepAny(): void
    {
        $form = $this->readForm($this->slotForm($this->block('kalaka/number-field', [
            'key' => 'adat',
            'label' => 'Adat',
            'decimals' => 1,
        ])));

        $html = implode('', array_map('render_block', $this->forms->blocks($form)));

        self::assertStringContainsString('step="any"', $html);
        self::assertStringContainsString('inputmode="decimal"', $html);
    }

    public function testAFormWithInvalidNumberSettingsIsAProblemNamingTheKey(): void
    {
        foreach (
            [
                ['min' => '1 000'],
                ['min' => '1.5', 'decimals' => 0],
                ['step' => '0'],
                ['min' => '2', 'max' => '1'],
            ] as $attributes
        ) {
            $id = $this->insertForm($this->slotFields() . $this->field('number', 'number', $attributes));
            try {
                $this->forms->definition($this->readForm($id));
                self::fail('An invalid number field was read.');
            } catch (SystemFailure $e) {
                self::assertStringEndsWith('invalid_number number', $e->getMessage());
            }
        }
        $fine = $this->insertForm($this->slotFields() . $this->field('number', 'number'));
        self::assertNotNull($this->forms->definition($this->readForm($fine))->field('number'));
    }
}
