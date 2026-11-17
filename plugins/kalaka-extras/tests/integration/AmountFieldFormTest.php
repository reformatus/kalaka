<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Extras;

use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * The amount field in a form: core's text field markup, plain, and the server's check of its limits at
 * submit, through the form like any field.
 */
final class AmountFieldFormTest extends FormsTestCase
{
    private function amountForm(): int
    {
        return $this->slotForm($this->block('kalaka/amount-field', [
            'key' => 'adomany',
            'label' => 'Adomány',
            'min' => 1000,
            'step' => 500,
            'max' => 100000,
        ]));
    }

    public function testItRendersCoresTextFieldMarkup(): void
    {
        $form = $this->readForm($this->amountForm());

        $html = implode('', array_map('render_block', $this->forms->blocks($form)));

        self::assertStringContainsString('data-kalaka-field="adomany"', $html);
        self::assertStringContainsString('<label for="kalaka-field-adomany">Adomány</label>', $html);
        self::assertStringContainsString('<input type="text" id="kalaka-field-adomany" name="adomany">', $html);
    }

    public function testTheSubmitStoresAWholeAmountAndRefusesOneOutsideTheLimits(): void
    {
        $definition = $this->forms->definition($this->readForm($this->amountForm()));
        $slot = ['name' => 'Teszt Elek', 'email' => 'teszt@example.org'];

        $stored = $this->forms->validate($definition, $slot + ['adomany' => '5 000'])->stored();
        self::assertSame('5000', $stored['adomany']);
        self::assertArrayNotHasKey('adomany', $this->forms->validate($definition, $slot + ['adomany' => ''])->stored());
        try {
            $this->forms->validate($definition, $slot + ['adomany' => '1200']);
            self::fail('Refused.');
        } catch (InvalidAnswers $refused) {
            self::assertSame(['adomany' => 'Az összeg 500 Ft többszöröse legyen.'], $refused->fields);
        }
    }
}
