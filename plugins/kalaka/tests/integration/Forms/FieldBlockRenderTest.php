<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use WP_UnitTestCase;

/** AC9: the markup of the core field blocks (D18), with no theme adapter. */
final class FieldBlockRenderTest extends WP_UnitTestCase
{
    /** @param array<string, mixed> $attributes */
    private function render(string $type, array $attributes): string
    {
        return render_block([
            'blockName' => 'kalaka/' . $type . '-field',
            'attrs' => $attributes,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);
    }

    /** The markup with runs of whitespace as one space and none between tags, to compare structure only. */
    private function squeezed(string $html): string
    {
        $html = trim((string) preg_replace('/\s+/', ' ', $html));

        return str_replace(['> <', ' </'], ['><', '</'], $html);
    }

    private function assertMarkup(string $expected, string $actual): void
    {
        self::assertSame($this->squeezed($expected), $this->squeezed($actual));
    }

    public function testTheBlocksAreRegisteredWithTheirAttributes(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        foreach (['name', 'email', 'phone', 'date', 'text', 'choice', 'consent'] as $type) {
            $block = $registry->get_registered('kalaka/' . $type . '-field');
            self::assertInstanceOf(\WP_Block_Type::class, $block, $type);
            self::assertSame(3, $block->api_version);
            self::assertArrayHasKey('key', $block->attributes);
            self::assertArrayHasKey('label', $block->attributes);
            self::assertArrayHasKey('required', $block->attributes);
            self::assertSame('kalaka', $block->category, $type);
            self::assertFalse($block->supports['html'] ?? true, $type);
            self::assertSame(['kalaka/form'], $block->ancestor, $type);
        }
        $name = $registry->get_registered('kalaka/name-field');
        $email = $registry->get_registered('kalaka/email-field');
        self::assertInstanceOf(\WP_Block_Type::class, $name);
        self::assertInstanceOf(\WP_Block_Type::class, $email);
        self::assertArrayHasKey('displayName', $name->attributes);
        self::assertArrayHasKey('contactEmail', $email->attributes);
    }

    /** The opening tag of a field's wrapper, as `get_block_wrapper_attributes()` builds it. */
    private function open(string $type, string $key, string $tag = 'div'): string
    {
        return '<' . $tag . ' class="kalaka-field wp-block-kalaka-' . $type . '-field"'
            . ' data-kalaka-field="' . $key . '">';
    }

    private function error(string $key): string
    {
        return '<span id="kalaka-field-' . $key . '-error" class="kalaka-field__error" hidden></span>';
    }

    private const MARKER = '<span class="kalaka-field__required" aria-hidden="true">*</span>';

    public function testTheBlocksHaveTheirTitles(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $titles = [
            'name' => 'Név',
            'email' => 'E-mail-cím',
            'phone' => 'Telefonszám',
            'date' => 'Dátum',
            'text' => 'Szöveg',
            'choice' => 'Választás',
            'consent' => 'Hozzájárulás',
        ];

        foreach ($titles as $type => $title) {
            $block = $registry->get_registered('kalaka/' . $type . '-field');
            self::assertInstanceOf(\WP_Block_Type::class, $block, $type);
            self::assertSame($title, $block->title, $type);
        }
        $categories = array_column(get_block_categories(get_post(self::factory()->post->create())), 'title', 'slug');
        self::assertSame('Kaláka', $categories['kalaka'] ?? null);
    }

    public function testTheTextLikeFieldsRenderALabelledInputAndAnErrorElement(): void
    {
        foreach (['phone' => 'tel', 'date' => 'date', 'text' => 'text'] as $type => $inputType) {
            $this->assertMarkup(
                $this->open($type, 'my_key') . '<label for="kalaka-field-my_key">Címke</label>'
                . '<input type="' . $inputType . '" id="kalaka-field-my_key" name="my_key">'
                . $this->error('my_key') . '</div>',
                $this->render($type, ['key' => 'my_key', 'label' => 'Címke'])
            );
            $this->assertMarkup(
                $this->open($type, 'my_key') . '<label for="kalaka-field-my_key">Címke' . self::MARKER . '</label>'
                . '<input type="' . $inputType . '" id="kalaka-field-my_key" name="my_key" required>'
                . $this->error('my_key') . '</div>',
                $this->render($type, ['key' => 'my_key', 'label' => 'Címke', 'required' => true])
            );
        }
    }

    public function testAMultilineTextRendersATextarea(): void
    {
        $this->assertMarkup(
            $this->open('text', 'story') . '<label for="kalaka-field-story">Mesélj' . self::MARKER . '</label>'
            . '<textarea id="kalaka-field-story" name="story" required></textarea>' . $this->error('story') . '</div>',
            $this->render('text', ['key' => 'story', 'label' => 'Mesélj', 'required' => true, 'multiline' => true])
        );
    }

    public function testTheTwoMarkedFieldsRenderRequiredAndTheMarkerWhateverTheirAttribute(): void
    {
        $this->assertMarkup(
            $this->open('email', 'email') . '<label for="kalaka-field-email">E-mail' . self::MARKER . '</label>'
            . '<input type="email" id="kalaka-field-email" name="email" required>' . $this->error('email') . '</div>',
            $this->render('email', ['key' => 'email', 'label' => 'E-mail', 'contactEmail' => true])
        );
        $this->assertMarkup(
            $this->open('name', 'name') . '<label for="kalaka-field-name">Név' . self::MARKER . '</label>'
            . '<input type="text" id="kalaka-field-name" name="name" required>' . $this->error('name') . '</div>',
            $this->render('name', ['key' => 'name', 'label' => 'Név', 'displayName' => true])
        );
        $optional = $this->render('email', ['key' => 'email2', 'label' => 'E-mail']);
        self::assertStringNotContainsString('required', $optional);
        self::assertStringNotContainsString('*', $optional);
        self::assertStringContainsString(
            'required',
            $this->render('email', ['key' => 'email2', 'label' => 'E-mail', 'required' => true])
        );
    }

    public function testASingleChoiceRendersARequiredRadioPerOption(): void
    {
        $options = [['value' => 'kicsi', 'label' => 'Kicsi'], ['value' => 'nagy', 'label' => 'Nagy']];

        $this->assertMarkup(
            $this->open('choice', 'size') . '<fieldset><legend>Méret' . self::MARKER . '</legend>'
            . '<label><input type="radio" name="size" value="kicsi" required> Kicsi</label>'
            . '<label><input type="radio" name="size" value="nagy" required> Nagy</label></fieldset>'
            . $this->error('size') . '</div>',
            $this->render('choice', ['key' => 'size', 'label' => 'Méret', 'required' => true, 'options' => $options])
        );
        self::assertStringNotContainsString(
            'required',
            $this->render('choice', ['key' => 'size', 'label' => 'Méret', 'options' => $options])
        );
    }

    public function testAMultipleChoiceRendersCheckboxesWithNoRequiredButTheMarker(): void
    {
        $options = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];

        $this->assertMarkup(
            $this->open('choice', 'extras') . '<fieldset><legend>Extrák' . self::MARKER . '</legend>'
            . '<label><input type="checkbox" name="extras[]" value="a"> A</label>'
            . '<label><input type="checkbox" name="extras[]" value="b"> B</label></fieldset>'
            . $this->error('extras') . '</div>',
            $this->render(
                'choice',
                ['key' => 'extras', 'label' => 'Extrák', 'required' => true, 'multiple' => true, 'options' => $options]
            )
        );
    }

    public function testAConsentRendersOneCheckboxWithTheLabelInsideTheLabelElement(): void
    {
        $this->assertMarkup(
            $this->open('consent', 'gdpr') . '<label>'
            . '<input type="checkbox" name="gdpr" value="1" required> Elfogadom' . self::MARKER . '</label>'
            . $this->error('gdpr') . '</div>',
            $this->render('consent', ['key' => 'gdpr', 'label' => 'Elfogadom', 'required' => true])
        );
        self::assertStringNotContainsString(
            'required',
            $this->render('consent', ['key' => 'gdpr', 'label' => 'Elfogadom'])
        );
    }

    public function testLabelsAndOptionLabelsKeepSafeMarkupAndLoseScripts(): void
    {
        $html = $this->render('text', ['key' => 'x', 'label' => 'Egy <b>félkövér</b><script>alert(1)</script> címke']);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('<b>félkövér</b>', $html);

        $choice = $this->render('choice', [
            'key' => 'x',
            'label' => 'L<script>alert(1)</script>',
            'options' => [['value' => 'a', 'label' => 'O<script>alert(2)</script>']],
        ]);
        self::assertStringNotContainsString('<script', $choice);
    }

    public function testKeysAndOptionValuesAreEscapedAsAttributes(): void
    {
        $html = $this->render('choice', [
            'key' => 'k"><i>',
            'label' => 'L',
            'options' => [['value' => 'v"><b>', 'label' => 'O']],
        ]);

        self::assertStringNotContainsString('<i>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('data-kalaka-field="k&quot;&gt;&lt;i&gt;"', $html);
        self::assertStringContainsString('value="v&quot;&gt;&lt;b&gt;"', $html);
    }

    public function testTheMarkupHasNoStylesScriptsOrClassesBeyondOurThreeAndTheBlocksOwn(): void
    {
        $options = [['value' => 'a', 'label' => 'A']];

        foreach (
            [
                'name' => $this->render('name', ['key' => 'k', 'label' => 'L', 'required' => true]),
                'choice' => $this->render('choice', ['key' => 'k', 'label' => 'L', 'options' => $options]),
                'consent' => $this->render('consent', ['key' => 'k', 'label' => 'L']),
                'text' => $this->render('text', ['key' => 'k', 'label' => 'L', 'multiline' => true]),
            ] as $type => $html
        ) {
            preg_match_all('/class="([^"]*)"/', $html, $classes);
            $names = array_unique(explode(' ', implode(' ', $classes[1])));
            self::assertSame(
                [],
                array_diff(
                    $names,
                    [
                        'kalaka-field',
                        'wp-block-kalaka-' . $type . '-field',
                        'kalaka-field__required',
                        'kalaka-field__error',
                    ]
                ),
                $html
            );
            self::assertStringNotContainsString('style', $html);
            self::assertStringNotContainsString('<script', $html);
        }
    }
}
