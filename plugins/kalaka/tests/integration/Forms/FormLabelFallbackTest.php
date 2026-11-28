<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use Kalaka\Forms\Module;
use Kalaka\Foundation\Api\Actor\CliActor;
use Kalaka\Foundation\Api\UseCase\Context;
use Kalaka\ParticipantFields\Api\ParticipantFields;
use Kalaka\Tests\Integration\Foundation\AdminProbe;

/**
 * A form shows its own question title and option labels; where it leaves one empty it shows the participant
 * field's (for a code-declared field the declarer's, its admin name).
 */
final class FormLabelFallbackTest extends FormsTestCase
{
    private const EVENT = 7;

    private const TIERS = [
        ['value' => 'korai', 'label' => 'Korai ár'],
        ['value' => 'alap', 'label' => 'Alap ár'],
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names it
    public function set_up(): void
    {
        parent::set_up();

        (new Module())->registerMeta();
        $this->runner->run(
            new AdminProbe(static function (array $input, Context $context): void {
                (new ParticipantFields())->declareExtensionField(
                    $context,
                    self::EVENT,
                    'price_tier',
                    'kalaka/choice-field',
                    'Díjkategória',
                    self::TIERS,
                    true,
                    'test_module'
                );
            }),
            [],
            new CliActor(null)
        );
    }

    /** @param list<array<string, mixed>> $options */
    private function choice(string $key, string $label, array $options): string
    {
        return $this->block('kalaka/choice-field', ['key' => $key, 'label' => $label, 'options' => $options]);
    }

    /** The form's page markup of its current version, block by block as the page renders it. */
    private function rendered(int $formId): string
    {
        return implode('', array_map(
            static fn (array $block): string => render_block($block),
            $this->forms->blocks($this->readForm($formId))
        ));
    }

    public function testAFormsOwnLabelsShowNotTheDeclaredOnes(): void
    {
        $id = $this->insertChecked($this->slotFields() . $this->choice('price_tier', 'Melyik ár?', [
            ['value' => 'korai', 'label' => 'Hamarabb'],
            ['value' => 'alap', 'label' => 'Rendes'],
        ]));

        $html = $this->rendered($id);

        self::assertStringContainsString('Melyik ár?', $html);
        self::assertStringContainsString('Hamarabb', $html);
        self::assertStringContainsString('Rendes', $html);
        self::assertStringNotContainsString('Díjkategória', $html);
        self::assertStringNotContainsString('Korai ár', $html);
        self::assertStringNotContainsString('Alap ár', $html);
    }

    public function testAnEmptyBlockLabelShowsTheDeclaredTitleAndAnEmptyOptionLabelTheDeclaredOne(): void
    {
        $id = $this->insertChecked($this->slotFields() . $this->choice('price_tier', '  ', [
            ['value' => 'korai', 'label' => ''],
            ['value' => 'alap', 'label' => 'Rendes'],
        ]));

        $html = $this->rendered($id);

        self::assertStringContainsString('Díjkategória', $html);
        self::assertStringContainsString('Korai ár', $html);
        self::assertStringContainsString('Rendes', $html);
        self::assertStringNotContainsString('Alap ár', $html);
    }

    public function testTheDefinitionReadsTheSameLabels(): void
    {
        $id = $this->insertChecked($this->slotFields() . $this->choice('price_tier', '', [
            ['value' => 'korai', 'label' => ''],
            ['value' => 'alap', 'label' => 'Rendes'],
        ]));

        $field = $this->forms->definition($this->readForm($id))->field('price_tier');

        self::assertNotNull($field);
        self::assertSame('Díjkategória', $field->label());
        self::assertSame(
            [['value' => 'korai', 'label' => 'Korai ár'], ['value' => 'alap', 'label' => 'Rendes']],
            $field->attributes['options']
        );
    }

    public function testAFormFieldWithAnEmptyLabelFallsBackToItsParticipantFieldToo(): void
    {
        $id = $this->insertChecked($this->slotFields() . $this->field('text', 'megjegyzes', ['label' => 'Megjegyzés']));
        wp_update_post([
            'ID' => $id,
            'post_content' => $this->slotFields() . $this->field('text', 'megjegyzes', ['label' => '']),
        ]);

        $html = $this->rendered($id);

        self::assertStringContainsString('Megjegyzés', $html);
    }
}
