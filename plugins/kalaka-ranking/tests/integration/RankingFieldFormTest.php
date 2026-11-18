<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Ranking;

use Kalaka\Events\PublishEvent;
use Kalaka\Forms\Api\InvalidAnswers;
use Kalaka\Forms\Engine\FormPage;
use Kalaka\Forms\Engine\FormPageView;
use Kalaka\Forms\EventFormHost;
use Kalaka\Forms\Module as FormsModule;
use Kalaka\Foundation\Api\Error\SystemFailure;
use Kalaka\Foundation\Api\Module\Contributions;
use Kalaka\Foundation\Api\UseCase\Runner;
use Kalaka\Ranking\RankingFieldType;
use Kalaka\Tests\Integration\Forms\FormsTestCase;
use Kalaka\Tests\Integration\Forms\PrefillingHost;

/**
 * The ranking field in a form: contributed and registered, checked at submit by the form's walk, rendered
 * with core's field markup, opened with a stored ranking in its order, and declared as the event's
 * participant field like any field.
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

    public function testItRendersRowsWithToggleCheckboxesInAChoiceGroupWithTheErrorElement(): void
    {
        $html = $this->render([
            'key' => 'modulok',
            'label' => 'Modulok',
            'required' => true,
            'options' => self::OPTIONS,
            'min' => 2,
            'max' => 3,
        ]);

        self::assertStringContainsString('data-kalaka-field="modulok"', $html);
        self::assertStringContainsString(
            'data-kalaka-ranking' . "\n" . '    data-kalaka-min="2"' . "\n"
                . '    data-kalaka-max="3" data-kalaka-required>',
            $html
        );
        self::assertMatchesRegularExpression('/<legend>Modulok<span class="kalaka-field__required"/', $html);
        // The two lists, named for screen readers (owner, Q4), the ranked one empty at load.
        self::assertStringContainsString(
            '<ol data-kalaka-ranked role="list" aria-label="Választott sorrend"></ol>',
            $html
        );
        self::assertStringContainsString('<ul data-kalaka-unranked role="list" aria-label="Többi lehetőség">', $html);
        // Each option: the browser's checkbox `<key>[]`, drawn as „+”, named by what it does to its option and
        // described by the counter and the minimum's line.
        self::assertSame(3, substr_count($html, 'type="checkbox" name="modulok[]"'));
        self::assertSame(0, substr_count($html, ' checked'));
        self::assertStringContainsString(
            'value="modul_b" data-kalaka-toggle aria-label="Modul B hozzáadása a sorrendhez"'
                . ' aria-describedby="kalaka-field-modulok-counter kalaka-field-modulok-minimum" autocomplete="off">',
            $html
        );
        // The text is no label: a tap on it does nothing.
        self::assertStringNotContainsString('<label', substr($html, (int) strpos($html, '</legend>')));
        self::assertStringContainsString('<span data-kalaka-label>Modul A</span>', $html);
        self::assertStringNotContainsString('wp-element-button" data-kalaka-move', $html);
        // The handle and the move buttons show only once the option is ranked; the buttons are core's outlined
        // base buttons, without `wp-element-button` (the filled look is the toggle's), and their name says which
        // option they move.
        self::assertSame(3, substr_count($html, '<span data-kalaka-handle aria-hidden="true" hidden>⠿</span>'));
        self::assertStringContainsString(
            '<button type="button" data-kalaka-move="up"'
                . ' aria-label="Modul B feljebb mozgatása" hidden>',
            $html
        );
        self::assertStringContainsString(
            '<button type="button" data-kalaka-move="down"'
                . ' aria-label="Modul C lejjebb mozgatása" hidden>',
            $html
        );
        // The minimum's empty rows (required: shown at once), a named list, the first with its line; the counter.
        self::assertStringContainsString(
            '<ol data-kalaka-empty role="list" aria-label="Üres helyek a sorrendben"' . "\n"
                . '            start="1"><li data-kalaka-empty-row id="kalaka-field-modulok-minimum">'
                . 'Adj hozzá legalább 2 lehetőséget a sorrendhez.</li>'
                . '<li data-kalaka-empty-row aria-hidden="true"></li></ol>',
            $html
        );
        self::assertStringContainsString(
            '<p data-kalaka-counter id="kalaka-field-modulok-counter">0 / 3 kiválasztva</p>',
            $html
        );
        self::assertStringContainsString('id="kalaka-field-modulok-error" class="kalaka-field__error" hidden', $html);
        self::assertStringContainsString(
            '<span class="kalaka-visually-hidden" aria-live="polite" data-kalaka-announce></span>',
            $html
        );

        // The options keep their authored order among the unranked ones.
        self::assertLessThan(strpos($html, 'modul_b'), strpos($html, 'modul_a'));
        self::assertLessThan(strpos($html, 'modul_c'), strpos($html, 'modul_b'));
    }

    public function testAnOptionalFieldShowsNoEmptyRowsAtLoadAndOneMeansEgy(): void
    {
        $optional = $this->render(['key' => 'modulok', 'options' => self::OPTIONS, 'min' => 2, 'max' => 3]);
        self::assertStringContainsString('start="1" hidden></ol>', $optional);
        self::assertStringNotContainsString('data-kalaka-required', $optional);

        $one = $this->render(['key' => 'modulok', 'required' => true, 'options' => self::OPTIONS, 'min' => 1]);
        self::assertStringContainsString(
            '<li data-kalaka-empty-row id="kalaka-field-modulok-minimum">'
                . 'Adj hozzá legalább egy lehetőséget a sorrendhez.</li>',
            $one
        );
        self::assertSame(
            'Adj hozzá még legalább egy lehetőséget a sorrendhez.',
            RankingFieldType::minimumLine(2, ['min' => 3])
        );
        self::assertSame(
            'Adj hozzá még legalább 2 lehetőséget a sorrendhez.',
            RankingFieldType::minimumLine(1, ['min' => 3])
        );
        self::assertSame(0, RankingFieldType::emptyRows(0, ['min' => 2]));
        self::assertSame(2, RankingFieldType::emptyRows(0, ['min' => 2, 'required' => true]));
        self::assertSame(1, RankingFieldType::emptyRows(1, ['min' => 2]));
    }

    public function testItsOptionsWithoutAValidKeyGetOneFromTheirLabelOnSaveAsAChoicesDo(): void
    {
        $options = [
            ['label' => 'Modul Á'],
            ['value' => 'modul_b', 'label' => 'Modul B'],
            ['value' => 'Rossz', 'label' => 'Modul C'],
            ['value' => 'modul_b', 'label' => 'Modul D'],
        ];
        $id = $this->insertChecked(
            $this->ranking('modulok', ['options' => $options]),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );
        self::assertSame('publish', get_post_status($id));

        $post = get_post($id);
        self::assertInstanceOf(\WP_Post::class, $post);
        $values = [];
        foreach (parse_blocks($post->post_content) as $block) {
            if ($block['blockName'] === RankingFieldType::BLOCK_NAME) {
                $values = array_column($block['attrs']['options'], 'value');
            }
        }
        // A typed key is kept; a missing, invalid or repeated one is derived from the label.
        self::assertSame(['modul_a', 'modul_b', 'modul_c', 'modul_d'], $values);
        self::assertSame(['modul_a', 'modul_b', 'modul_c', 'modul_d'], RankingFieldType::optionKeys(
            $this->forms->definition($this->readForm($id))->fields[0]->attributes
        ));
    }

    public function testTheDefinitionCheckRefusesARankingWithoutAValidOptionAsAChoices(): void
    {
        $bad = [
            'no options' => [],
            'an option without a key' => [['label' => 'Modul A']],
            'a repeated key' => [['value' => 'modul_a', 'label' => 'Modul A'], ['value' => 'modul_a', 'label' => 'B']],
        ];
        foreach ($bad as $why => $options) {
            $id = $this->insertUnderived(
                $this->ranking('modulok', ['options' => $options]),
                ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
            );
            try {
                $this->forms->definition($this->readForm($id));
                self::fail('A ranking with ' . $why . ' was accepted.');
            } catch (SystemFailure $e) {
                self::assertStringEndsWith(' invalid_options modulok', $e->getMessage(), $why);
            }
        }
        // The save check refuses to publish it: a new form stays a draft.
        $refused = $this->insertChecked(
            $this->ranking('modulok', ['options' => []]),
            ['kalaka_event_id' => self::EVENT, 'kalaka_placement' => 'additional']
        );
        self::assertSame('draft', get_post_status($refused));
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

    /**
     * The form page of a published event's registration form holding the ranking, opened with the stored values
     * (`FormHost::prefill()`: a draft, an additional form's current values).
     *
     * @param array<string, string> $stored
     */
    private function page(array $stored, int $max = 2): string
    {
        $day = static fn (int $offset): string => Runner::current()->clock()->now()->setTimezone(wp_timezone())
            ->setTime(0, 0)->modify(sprintf('%+d days', $offset))->format('Y-m-d H:i');
        $event = $this->create('Nyári tábor');
        $this->update($event, [
            'name' => 'Nyári tábor',
            'registration_opens_at' => $day(-1),
            'registration_closes_at' => $day(10),
            'starts_at' => $day(30),
            'ends_at' => $day(36),
        ]);
        $this->transition(new PublishEvent(), $event);
        $formId = $this->insertForm(
            $this->slotFields() . $this->ranking('modulok', ['required' => true, 'max' => $max]),
            ['kalaka_event_id' => $event]
        );

        return (new FormPage(new PrefillingHost(new EventFormHost(), $stored)))->html(
            $formId,
            static function (FormPageView $view): void {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the rendered form
                echo $view->content;
            }
        );
    }

    /** The markup of the ranked list. */
    private static function ranked(string $html): string
    {
        preg_match('#<ol data-kalaka-ranked[^>]*>(.*?)</ol>#s', $html, $ranked);

        return $ranked[1] ?? '';
    }

    public function testAStoredRankingOpensInItsOrderWithNoErrorShown(): void
    {
        $html = $this->page(['modulok' => '["modul_c","modul_a"]']);

        $ranked = self::ranked($html);
        self::assertSame(2, preg_match_all('#value="(modul_[a-c])"[^>]* checked>#', $ranked, $keys));
        self::assertSame(['modul_c', 'modul_a'], $keys[1]);
        self::assertSame(2, substr_count($ranked, '<span data-kalaka-handle aria-hidden="true">⠿</span>'));
        self::assertStringContainsString('aria-label="Modul C eltávolítása a sorrendből"', $ranked);
        // Pass 4's markup: each toggle described by the counter and the line, the empty-row list named.
        self::assertStringContainsString(
            ' aria-describedby="kalaka-field-modulok-counter kalaka-field-modulok-minimum"',
            $ranked
        );
        self::assertStringContainsString(
            '<ol data-kalaka-empty role="list" aria-label="Üres helyek a sorrendben"',
            $html
        );
        // The minimum is met: no empty rows; the counter counts the stored ones; the rest stay unranked.
        self::assertMatchesRegularExpression('#<ol data-kalaka-empty [^>]*start="3" hidden></ol>#', $html);
        self::assertStringContainsString('>2 / 2 kiválasztva</p>', $html);
        preg_match('#<ul data-kalaka-unranked[^>]*>(.*?)</ul>#s', $html, $others);
        self::assertStringContainsString('value="modul_b"', $others[1] ?? '');
        self::assertStringNotContainsString(' checked', $others[1] ?? '');
        // No error: the form renders, and nothing is marked invalid.
        self::assertStringNotContainsString('Hibás vagy hiányzó adat.', $html);
        self::assertStringNotContainsString('aria-invalid', $html);
        self::assertMatchesRegularExpression('#<span id="kalaka-field-modulok-error" [^>]*hidden></span>#', $html);
    }

    public function testAStoredRankingWithUnknownKeysAndRepeatsOpensWithTheRest(): void
    {
        $html = $this->page(['modulok' => '["modul_b","modul_x","modul_b","modul_a"]'], 3);

        self::assertSame(2, preg_match_all('#value="(modul_[a-c])"[^>]* checked>#', self::ranked($html), $keys));
        self::assertSame(['modul_b', 'modul_a'], $keys[1]);
        self::assertStringNotContainsString('modul_x', $html);
        self::assertStringNotContainsString('Hibás vagy hiányzó adat.', $html);
    }

    public function testASubmittedRankingOpensAgainInTheOrderItWasSent(): void
    {
        // The form sends `modulok[]` in rank order; the type stores that list, and the page opens with it.
        $definition = $this->forms->definition($this->readForm($this->slotForm($this->ranking())));
        $stored = $this->forms->validate($definition, [
            'name' => 'Teszt Anna',
            'email' => 'teszt.anna@example.org',
            'modulok' => ['modul_b', 'modul_c'],
        ])->stored()['modulok'];

        $html = $this->page(['modulok' => $stored]);

        self::assertSame(2, preg_match_all('#value="(modul_[a-c])"[^>]* checked>#', self::ranked($html), $keys));
        self::assertSame(['modul_b', 'modul_c'], $keys[1]);
        self::assertStringNotContainsString('Hibás vagy hiányzó adat.', $html);
    }
}
