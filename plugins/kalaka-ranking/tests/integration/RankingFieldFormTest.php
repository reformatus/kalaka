<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ranking;

use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Ranking\RankingFieldType;
use Kalaka\Tests\Integration\Forms\FormsTestCase;

/**
 * The ranking field in a form: contributed and registered, checked at submit by the form's walk, rendered
 * with core's field markup, and declared as the event's participant field like any field.
 */
final class RankingFieldFormTest extends FormsTestCase
{
    private const EVENT = 7;

    private const OPTIONS = [
        ['value' => 'modul_a', 'label' => 'Modul A'],
        ['value' => 'modul_b', 'label' => 'Modul B'],
        ['value' => 'modul_c', 'label' => 'Modul C'],
    ];

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- the WordPress test case names this hook in snake case
    public function set_up(): void
    {
        parent::set_up();

        // The harness unregisters all meta keys after each test, so register them again the way `init` does.
        (new FormsModule())->registerMeta();
        wp_set_current_user($this->fullAccessUser());
    }

    /** @param array<string, mixed> $attributes */
    private function ranking(string $key = 'modulok', array $attributes = []): string
    {
        return $this->block(RankingFieldType::BLOCK_NAME, $attributes + [
            'key' => $key,
            'label' => 'Modulok',
            'options' => self::OPTIONS,
            'min' => 1,
            'max' => 2,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function render(array $attributes): string
    {
        return render_block([
            'blockName' => RankingFieldType::BLOCK_NAME,
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    public function testTheTypeIsContributedAndItsBlockRegistered(): void
    {
        self::assertInstanceOf(
            RankingFieldType::class,
            Contributions::current()->fieldTypes()[RankingFieldType::BLOCK_NAME] ?? null
        );
        $block = \WP_Block_Type_Registry::get_instance()->get_registered(RankingFieldType::BLOCK_NAME);
        self::assertInstanceOf(\WP_Block_Type::class, $block);
        self::assertSame('kalaka', $block->category);
        self::assertSame(1, $block->attributes['min']['default'] ?? null);
        self::assertSame(3, $block->attributes['max']['default'] ?? null);
        self::assertArrayHasKey('options', $block->attributes);
    }

    public function testASubmitStoresTheOrderedKeysAndRefusesTooMany(): void
    {
        $definition = $this->forms->definition($this->readForm($this->slotForm($this->ranking())));
        $required = ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org'];

        $validated = $this->forms->validate($definition, $required + ['modulok' => ['modul_c', 'modul_a']]);
        self::assertSame('["modul_c","modul_a"]', $validated->stored()['modulok']);

        try {
            $this->forms->validate($definition, $required + ['modulok' => ['modul_c', 'modul_a', 'modul_b']]);
            self::fail('Three options were accepted with max 2.');
        } catch (InvalidAnswers $e) {
            self::assertSame(['modulok' => 'Legfeljebb 2 lehetőséget válassz.'], $e->fields);
        }
    }

    public function testARequiredRankingLeftEmptyHasCoresRequiredError(): void
    {
        $definition = $this->forms->definition(
            $this->readForm($this->slotForm($this->ranking('modulok', ['required' => true])))
        );

        try {
            $this->forms->validate(
                $definition,
                ['name' => 'Teszt Anna', 'email' => 'teszt.anna@example.org', 'modulok' => []]
            );
            self::fail('An empty required ranking was accepted.');
        } catch (InvalidAnswers $e) {
            self::assertSame(['modulok' => 'Ezt a mezőt ki kell töltened.'], $e->fields);
        }
    }

    public function testItRendersCheckboxesInAChoiceGroupWithTheErrorElement(): void
    {
        $html = $this->render([
            'key' => 'modulok',
            'label' => 'Modulok',
            'required' => true,
            'options' => self::OPTIONS,
        ]);

        self::assertStringContainsString('data-kalaka-field="modulok"', $html);
        self::assertStringContainsString('data-kalaka-ranking', $html);
        self::assertMatchesRegularExpression('/<legend>Modulok<span class="kalaka-field__required"/', $html);
        self::assertStringContainsString('<ol data-kalaka-ranked></ol>', $html);
        self::assertSame(3, substr_count($html, 'type="checkbox" name="modulok[]"'));
        self::assertSame(0, substr_count($html, ' checked'));
        self::assertStringContainsString('value="modul_b" autocomplete="off">', $html);
        // An unranked option's two buttons are hidden.
        self::assertSame(3, substr_count($html, '<button type="button" data-kalaka-move="up" hidden>Feljebb</button>'));
        self::assertSame(
            3,
            substr_count($html, '<button type="button" data-kalaka-move="down" hidden>Lejjebb</button>')
        );
        self::assertStringContainsString('id="kalaka-field-modulok-error" class="kalaka-field__error" hidden', $html);
        // The live region that tells a screen reader an option's new place after a move.
        self::assertStringContainsString(
            '<span class="screen-reader-text" aria-live="polite" data-kalaka-announce></span>',
            $html
        );

        // The options keep their authored order among the unranked ones.
        self::assertLessThan(strpos($html, 'modul_b'), strpos($html, 'modul_a'));
        self::assertLessThan(strpos($html, 'modul_c'), strpos($html, 'modul_b'));
    }

    public function testItIsDeclaredAsTheEventsParticipantFieldWithoutOptions(): void
    {
        $this->insertChecked(
            $this->ranking(),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );

        $fields = [];
        foreach ($this->forms->participantFields(self::EVENT) as $field) {
            $fields[$field->key] = $field;
        }
        self::assertArrayHasKey('modulok', $fields);
        self::assertSame(RankingFieldType::BLOCK_NAME, $fields['modulok']->type);
        self::assertSame('Modulok', $fields['modulok']->title);
        self::assertSame('form', $fields['modulok']->source);
        self::assertSame([], $fields['modulok']->options);
        self::assertSame('Rangsor', $fields['modulok']->typeLabel);
    }
}
