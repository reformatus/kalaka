<?php

declare(strict_types=1);

namespace Kalaka\Tests\Integration\Forms;

use WP_UnitTestCase;

/** AC9: the markup of the core field blocks (D18). */
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
        }
        $name = $registry->get_registered('kalaka/name-field');
        $email = $registry->get_registered('kalaka/email-field');
        self::assertInstanceOf(\WP_Block_Type::class, $name);
        self::assertInstanceOf(\WP_Block_Type::class, $email);
        self::assertArrayHasKey('displayName', $name->attributes);
        self::assertArrayHasKey('contactEmail', $email->attributes);
    }

    public function testTheTextLikeFieldsRenderALabelledInput(): void
    {
        foreach (['phone' => 'tel', 'date' => 'date', 'text' => 'text'] as $type => $inputType) {
            $this->assertMarkup(
                '<div class="kalaka-field" data-kalaka-field="my_key"><label for="kalaka-field-my_key">Címke</label>'
                . '<input type="' . $inputType . '" id="kalaka-field-my_key" name="my_key"></div>',
                $this->render($type, ['key' => 'my_key', 'label' => 'Címke'])
            );
            $this->assertMarkup(
                '<div class="kalaka-field" data-kalaka-field="my_key"><label for="kalaka-field-my_key">Címke</label>'
                . '<input type="' . $inputType . '" id="kalaka-field-my_key" name="my_key" required></div>',
                $this->render($type, ['key' => 'my_key', 'label' => 'Címke', 'required' => true])
            );
        }
    }

    public function testAMultilineTextRendersATextarea(): void
    {
        $this->assertMarkup(
            '<div class="kalaka-field" data-kalaka-field="story"><label for="kalaka-field-story">Mesélj</label>'
            . '<textarea id="kalaka-field-story" name="story" required></textarea></div>',
            $this->render('text', ['key' => 'story', 'label' => 'Mesélj', 'required' => true, 'multiline' => true])
        );
    }

    public function testTheTwoMarkedFieldsRenderRequiredWhateverTheirAttribute(): void
    {
        $this->assertMarkup(
            '<div class="kalaka-field" data-kalaka-field="email"><label for="kalaka-field-email">E-mail</label>'
            . '<input type="email" id="kalaka-field-email" name="email" required></div>',
            $this->render('email', ['key' => 'email', 'label' => 'E-mail', 'contactEmail' => true])
        );
        $this->assertMarkup(
            '<div class="kalaka-field" data-kalaka-field="name"><label for="kalaka-field-name">Név</label>'
            . '<input type="text" id="kalaka-field-name" name="name" required></div>',
            $this->render('name', ['key' => 'name', 'label' => 'Név', 'displayName' => true])
        );
        self::assertStringNotContainsString(
            'required',
            $this->render('email', ['key' => 'email2', 'label' => 'E-mail'])
        );
        self::assertStringContainsString(
            'required',
            $this->render('email', ['key' => 'email2', 'label' => 'E-mail', 'required' => true])
        );
    }

    public function testASingleChoiceRendersARequiredRadioPerOption(): void
    {
        $options = [['value' => 'kicsi', 'label' => 'Kicsi'], ['value' => 'nagy', 'label' => 'Nagy']];

        $this->assertMarkup(
            '<div class="kalaka-field" data-kalaka-field="size"><fieldset><legend>Méret</legend>'
            . '<label><input type="radio" name="size" value="kicsi" required> Kicsi</label>'
            . '<label><input type="radio" name="size" value="nagy" required> Nagy</label></fieldset></div>',
            $this->render('choice', ['key' => 'size', 'label' => 'Méret', 'required' => true, 'options' => $options])
        );
        self::assertStringNotContainsString(
            'required',
            $this->render('choice', ['key' => 'size', 'label' => 'Méret', 'options' => $options])
        );
    }

    public function testAMultipleChoiceRendersCheckboxesWithNoRequired(): void
    {
        $options = [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']];

        $this->assertMarkup(
            '<div class="kalaka-field" data-kalaka-field="extras"><fieldset><legend>Extrák</legend>'
            . '<label><input type="checkbox" name="extras[]" value="a"> A</label>'
            . '<label><input type="checkbox" name="extras[]" value="b"> B</label></fieldset></div>',
            $this->render(
                'choice',
                ['key' => 'extras', 'label' => 'Extrák', 'required' => true, 'multiple' => true, 'options' => $options]
            )
        );
    }

    public function testAConsentRendersOneCheckboxWithTheLabelInsideTheLabelElement(): void
    {
        $this->assertMarkup(
            '<div class="kalaka-field" data-kalaka-field="gdpr"><label>'
            . '<input type="checkbox" name="gdpr" value="1" required> Elfogadom</label></div>',
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

    public function testTheMarkupHasNoStylesScriptsOrClassesBeyondTheFieldClass(): void
    {
        $options = [['value' => 'a', 'label' => 'A']];

        foreach (
            [
                $this->render('name', ['key' => 'k', 'label' => 'L']),
                $this->render('choice', ['key' => 'k', 'label' => 'L', 'options' => $options]),
                $this->render('consent', ['key' => 'k', 'label' => 'L']),
                $this->render('text', ['key' => 'k', 'label' => 'L', 'multiline' => true]),
            ] as $html
        ) {
            self::assertSame(1, substr_count($html, 'class='), $html);
            self::assertStringContainsString('class="kalaka-field"', $html);
            self::assertStringNotContainsString('style', $html);
            self::assertStringNotContainsString('<script', $html);
        }
    }
}
